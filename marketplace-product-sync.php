<?php
/**
 * Plugin Name: همگام‌ساز چندمنبعی محصولات ووکامرس
 * Description: همگام‌سازی خودکار محصولات بین چند فروشگاه ووکامرس با نگاشت فیلدها، مقادیر ثابت و پیش‌فرض، زمان‌بندی و اجرای دستی.
 * Version:     1.4.4
 * Author:      Toranide
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: marketplace-product-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MPSync_Marketplace_Product_Sync {
    const VERSION = '1.4.4';
    const OPTION_SOURCES = 'mpsync_sources_v1';
    const OPTION_STATES  = 'mpsync_states_v1';
    const OPTION_LOGS    = 'mpsync_logs_v1';
    const OPTION_SIGS    = 'mpsync_schedule_signatures_v1';
    const GROUP_PREFIX   = 'mpsync-source-';
    const SCHEDULE_HOOK  = 'mpsync_scheduled_run';
    const PAGE_HOOK      = 'mpsync_process_sync_page';
    const PER_PAGE       = 25;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_notices', array($this, 'dependency_notice'));
        add_action('admin_init', array($this, 'maybe_upgrade_full_mapping'), 39);
        add_action('admin_init', array($this, 'maybe_upgrade_include_all_products_v14'), 40);
        add_action('admin_init', array($this, 'ensure_schedules'), 41);
        add_action('admin_post_mpsync_save_source', array($this, 'handle_save_source'));
        add_action('admin_post_mpsync_delete_source', array($this, 'handle_delete_source'));
        add_action('admin_post_mpsync_run_now', array($this, 'handle_run_now'));
        add_action('admin_post_mpsync_test_source', array($this, 'handle_test_source'));
        add_action(self::SCHEDULE_HOOK, array($this, 'handle_scheduled_run'), 10, 1);
        add_action(self::PAGE_HOOK, array($this, 'handle_page_job'), 10, 2);
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
    }

    public static function activate() {
        if (false === get_option(self::OPTION_SOURCES, false)) {
            add_option(self::OPTION_SOURCES, array(), '', false);
        }
        if (false === get_option(self::OPTION_STATES, false)) {
            add_option(self::OPTION_STATES, array(), '', false);
        }
        if (false === get_option(self::OPTION_LOGS, false)) {
            add_option(self::OPTION_LOGS, array(), '', false);
        }
    }

    public static function deactivate() {
        $sources = get_option(self::OPTION_SOURCES, array());
        if (is_array($sources)) {
            foreach ($sources as $source) {
                if (!empty($source['id'])) {
                    self::clear_scheduled_actions($source['id']);
                }
            }
        }

        // Clear the saved signatures as well. Otherwise, after reactivation,
        // ensure_schedules() could believe the old jobs still exist and skip
        // recreating the recurring source jobs that deactivation removed.
        delete_option(self::OPTION_SIGS);
    }

    private static function can_manage() {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public function register_admin_menu() {
        add_menu_page(
            'همگام‌ساز محصولات',
            'همگام‌ساز محصولات',
            'manage_woocommerce',
            'mpsync-marketplace',
            array($this, 'render_admin_page'),
            'dashicons-update',
            56
        );
    }

    public function dependency_notice() {
        if (!self::can_manage() || class_exists('WooCommerce')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>همگام‌ساز چندمنبعی محصولات ووکامرس:</strong> برای استفاده از این افزونه، ابتدا WooCommerce را نصب و فعال کنید.</p></div>';
    }

    private function sources() {
        $sources = get_option(self::OPTION_SOURCES, array());
        return is_array($sources) ? $sources : array();
    }

    private function states() {
        $states = get_option(self::OPTION_STATES, array());
        return is_array($states) ? $states : array();
    }

    private function get_source($source_id) {
        foreach ($this->sources() as $source) {
            if (isset($source['id']) && hash_equals((string) $source['id'], (string) $source_id)) {
                return $source;
            }
        }
        return null;
    }

    private function source_for_runtime($source) {
        if (!is_array($source)) {
            return $source;
        }
        $source['consumer_key']    = $this->decrypt_secret(isset($source['consumer_key']) ? $source['consumer_key'] : '');
        $source['consumer_secret'] = $this->decrypt_secret(isset($source['consumer_secret']) ? $source['consumer_secret'] : '');
        return $source;
    }

    private function source_group($source_id) {
        return self::GROUP_PREFIX . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $source_id), 0, 32);
    }

    private static function clear_scheduled_run_actions($source_id) {
        $group = self::GROUP_PREFIX . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $source_id), 0, 32);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::SCHEDULE_HOOK, array((string) $source_id), $group);
        }
        wp_clear_scheduled_hook(self::SCHEDULE_HOOK, array((string) $source_id));
    }

    private static function clear_scheduled_actions($source_id) {
        self::clear_scheduled_run_actions($source_id);
        $group = self::GROUP_PREFIX . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $source_id), 0, 32);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::PAGE_HOOK, null, $group);
        }
        wp_clear_scheduled_hook(self::PAGE_HOOK, array((string) $source_id));
    }

    /**
     * One-time upgrade for sources created by earlier builds: append missing
     * WooCommerce product fields so existing configurations gain the full catalog
     * without deleting the user's current custom mappings.
     */
    public function maybe_upgrade_full_mapping() {
        if (get_option('mpsync_full_mapping_migrated_v12')) {
            return;
        }
        $sources = $this->sources();
        $defaults = $this->default_mapping();
        foreach ($sources as &$source) {
            $mapping = isset($source['mapping']) && is_array($source['mapping']) ? $source['mapping'] : array();
            $present = array();
            foreach ($mapping as $row) {
                if (is_array($row) && !empty($row['target'])) {
                    $present[(string) $row['target']] = true;
                }
            }
            foreach ($defaults as $row) {
                if (empty($present[$row['target']])) {
                    $mapping[] = $row;
                    $present[$row['target']] = true;
                }
            }
            $source['mapping'] = $mapping;
        }
        unset($source);
        if ($sources) {
            update_option(self::OPTION_SOURCES, array_values($sources), false);
        }
        update_option('mpsync_full_mapping_migrated_v12', 1, false);
    }

    /**
     * One-time migration: include every readable product status and force one full
     * reconciliation so products previously hidden by the API's default status
     * filter can be backfilled without deleting the existing destination products.
     */
    public function maybe_upgrade_include_all_products_v14() {
        if (get_option('mpsync_v14_all_status_backfill_done')) {
            return;
        }
        $sources = $this->sources();
        foreach ($sources as &$source) {
            if (!empty($source['id'])) {
                $source['force_full_sync'] = 1;
            }
        }
        unset($source);
        if ($sources) {
            update_option(self::OPTION_SOURCES, array_values($sources), false);
        }
        update_option('mpsync_v14_all_status_backfill_done', 1, false);
    }

    public function ensure_schedules() {
        if (!function_exists('as_schedule_single_action') && !function_exists('wp_schedule_single_event')) {
            return;
        }
        $sources = $this->sources();
        $signatures = get_option(self::OPTION_SIGS, array());
        if (!is_array($signatures)) {
            $signatures = array();
        }
        $active_ids = array();
        foreach ($sources as $source) {
            if (empty($source['id'])) {
                continue;
            }
            $id = (string) $source['id'];
            $active_ids[] = $id;
            $signature = md5(wp_json_encode(array(
                'enabled' => !empty($source['enabled']),
                'interval_hours' => isset($source['interval_hours']) ? (int) $source['interval_hours'] : 24,
                'schedule_time' => isset($source['schedule_time']) ? $source['schedule_time'] : '03:00',
            )));
            if (!isset($signatures[$id]) || $signatures[$id] !== $signature) {
                $this->schedule_source($source);
                $signatures[$id] = $signature;
            }
        }
        foreach (array_keys($signatures) as $old_id) {
            if (!in_array((string) $old_id, $active_ids, true)) {
                self::clear_scheduled_actions($old_id);
                unset($signatures[$old_id]);
            }
        }
        update_option(self::OPTION_SIGS, $signatures, false);
    }

    private function schedule_source($source) {
        if (empty($source['id'])) {
            return;
        }
        $id = (string) $source['id'];
        self::clear_scheduled_run_actions($id);
        if (empty($source['enabled'])) {
            $signatures = get_option(self::OPTION_SIGS, array());
            if (is_array($signatures)) {
                $signatures[$id] = md5(wp_json_encode(array('enabled' => false)));
                update_option(self::OPTION_SIGS, $signatures, false);
            }
            return;
        }
        $timestamp = $this->next_run_timestamp($source);
        $group = $this->source_group($id);
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::SCHEDULE_HOOK, array($id), $group, false);
        } else {
            wp_schedule_single_event($timestamp, self::SCHEDULE_HOOK, array($id));
        }
        $signatures = get_option(self::OPTION_SIGS, array());
        if (!is_array($signatures)) {
            $signatures = array();
        }
        $signatures[$id] = md5(wp_json_encode(array(
            'enabled' => true,
            'interval_hours' => isset($source['interval_hours']) ? (int) $source['interval_hours'] : 24,
            'schedule_time' => isset($source['schedule_time']) ? $source['schedule_time'] : '03:00',
        )));
        update_option(self::OPTION_SIGS, $signatures, false);
    }

    private function next_run_timestamp($source) {
        $timezone = wp_timezone();
        $now = new DateTimeImmutable('now', $timezone);
        $time = isset($source['schedule_time']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $source['schedule_time'])
            ? $source['schedule_time'] : '03:00';
        list($hour, $minute) = array_map('intval', explode(':', $time));
        $candidate = $now->setTime($hour, $minute, 0);
        $interval = max(1, min(168, isset($source['interval_hours']) ? (int) $source['interval_hours'] : 24));
        if (24 === $interval) {
            if ($candidate <= $now) {
                $candidate = $candidate->modify('+1 day');
            }
        } else {
            while ($candidate <= $now) {
                $candidate = $candidate->modify('+' . $interval . ' hours');
            }
        }
        return $candidate->getTimestamp();
    }

    public function handle_scheduled_run($source_id) {
        $source = $this->get_source($source_id);
        if (!$source || empty($source['enabled'])) {
            return;
        }
        // Plan the next occurrence first; the sync itself may take several queued page jobs.
        $this->schedule_next_occurrence($source);
        $this->start_sync((string) $source_id, 'scheduled');
    }

    private function schedule_next_occurrence($source) {
        $id = (string) $source['id'];
        $group = $this->source_group($id);
        $timestamp = $this->next_run_timestamp($source);
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, self::SCHEDULE_HOOK, array($id), $group, false);
        } else {
            wp_schedule_single_event($timestamp, self::SCHEDULE_HOOK, array($id));
        }
    }

    public function handle_page_job($source_id, $scheduled_page = null) {
        $this->process_next_page((string) $source_id, null === $scheduled_page ? null : absint($scheduled_page));
    }

    private function queue_next_page($source_id, $page) {
        $id = (string) $source_id;
        $group = $this->source_group($id);
        $timestamp = time() + 5;
        if (function_exists('as_schedule_single_action')) {
            // Use the page number in the action arguments. Duplicate/replayed jobs are ignored by the state-page check.
            as_schedule_single_action($timestamp, self::PAGE_HOOK, array($id, absint($page)), $group, false);
        } elseif (!wp_next_scheduled(self::PAGE_HOOK, array($id))) {
            // WordPress-Cron fallback stores only the source ID; state tracks the next page.
            wp_schedule_single_event($timestamp, self::PAGE_HOOK, array($id));
        }
    }

    private function start_sync($source_id, $origin = 'manual') {
        $source = $this->get_source($source_id);
        if (!$source) {
            return new WP_Error('mpsync_source_missing', 'منبع پیدا نشد.');
        }
        $states = $this->states();
        $old_state = isset($states[$source_id]) && is_array($states[$source_id]) ? $states[$source_id] : array();
        if (isset($old_state['status'], $old_state['started_epoch']) && 'running' === $old_state['status'] && (time() - (int) $old_state['started_epoch']) < 1800) {
            return new WP_Error('mpsync_already_running', 'همگام‌سازی این منبع در حال اجراست.');
        }
        $started_epoch = time();
        $state = array(
            'status' => 'running',
            'origin' => sanitize_key($origin),
            'page' => 1,
            'phase' => 'products',
            'after_epoch' => (!empty($source['force_full_sync']) ? 0 : (!empty($source['last_sync_gmt']) ? max(0, strtotime($source['last_sync_gmt'] . ' UTC') - 120) : 0)),
            'started_epoch' => $started_epoch,
            'updated_epoch' => $started_epoch,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'last_error' => '',
        );
        $states[$source_id] = $state;
        update_option(self::OPTION_STATES, $states, false);
        $this->add_log($source_id, 'info', 'همگام‌سازی شروع شد.');
        return $this->process_next_page($source_id);
    }

    private function process_next_page($source_id, $scheduled_page = null) {
        $source_stored = $this->get_source($source_id);
        if (!$source_stored) {
            return new WP_Error('mpsync_source_missing', 'منبع پیدا نشد.');
        }
        $states = $this->states();
        if (empty($states[$source_id]) || !is_array($states[$source_id]) || 'running' !== $states[$source_id]['status']) {
            return false;
        }
        $state = $states[$source_id];
        $page = max(1, (int) $state['page']);
        $phase = isset($state['phase']) ? $state['phase'] : 'products';
        $is_variable_phase = ('variable_pass' === $phase);
        if (null !== $scheduled_page && $page !== (int) $scheduled_page) {
            return false; // Ignore duplicate or stale queued jobs.
        }
        $source = $this->source_for_runtime($source_stored);
        $params = array('page' => $page, 'per_page' => self::PER_PAGE, 'status' => 'any');
        if ($is_variable_phase) {
            // Variations can change without touching their parent product's modified date.
            // Revisit every variable parent on each run so its variations stay current.
            $params['type'] = 'variable';
        } elseif (!empty($state['after_epoch'])) {
            $params['modified_after'] = gmdate('Y-m-d\TH:i:s', (int) $state['after_epoch']);
            $params['dates_are_gmt'] = 'true';
        }

        $response = $this->request_api($source, $params);
        if (is_wp_error($response)) {
            $state['status'] = 'failed';
            $state['last_error'] = $response->get_error_message();
            $state['updated_epoch'] = time();
            $states[$source_id] = $state;
            update_option(self::OPTION_STATES, $states, false);
            $this->add_log($source_id, 'error', 'واکشی صفحه ' . $page . ' ناموفق بود: ' . $response->get_error_message());
            return $response;
        }

        if (!$is_variable_phase && 1 === $page && isset($response['total_items'])) {
            $state['source_total'] = max(0, (int) $response['total_items']);
        }

        $products = $response['products'];
        if (!is_array($products)) {
            $products = array();
        }
        foreach ($products as $remote_product) {
            if (!is_array($remote_product) || empty($remote_product['id'])) {
                $state['errors']++;
                $this->add_log($source_id, 'error', 'یک رکورد محصول بدون شناسه دریافت شد و نادیده گرفته شد.');
                continue;
            }
            $result = $this->import_product($source, $remote_product, $is_variable_phase);
            if (is_wp_error($result)) {
                $state['errors']++;
                $state['last_error'] = $result->get_error_message();
                $this->add_log($source_id, 'error', 'محصول مبدأ #' . absint($remote_product['id']) . ': ' . $result->get_error_message());
            } elseif (!$is_variable_phase && 'created' === $result) {
                $state['created']++;
            } elseif (!$is_variable_phase && 'updated' === $result) {
                $state['updated']++;
            } elseif (!$is_variable_phase) {
                $state['skipped']++;
            }
        }

        $total_pages = isset($response['total_pages']) ? (int) $response['total_pages'] : 0;
        $has_more = $total_pages > 0 ? ($page < $total_pages) : (count($products) >= self::PER_PAGE);
        $state['updated_epoch'] = time();
        if ($has_more) {
            $state['page'] = $page + 1;
            $states[$source_id] = $state;
            update_option(self::OPTION_STATES, $states, false);
            $this->queue_next_page($source_id, $page + 1);
            return true;
        }

        if (!$is_variable_phase) {
            $state['phase'] = 'variable_pass';
            $state['page'] = 1;
            $state['updated_epoch'] = time();
            $states[$source_id] = $state;
            update_option(self::OPTION_STATES, $states, false);
            $this->add_log($source_id, 'info', 'مرحله دوم: بررسی همه محصولات متغیر و وارییشن‌های آن‌ها برای دریافت تغییرات مستقل وارییشن‌ها.');
            $this->queue_next_page($source_id, 1);
            return true;
        }

        if (0 === (int) $state['errors']) {
            $source_list = $this->sources();
            foreach ($source_list as &$source_item) {
                if (isset($source_item['id']) && (string) $source_item['id'] === (string) $source_id) {
                    // Use the beginning of this run as checkpoint, so changes arriving while syncing are collected next time.
                    $source_item['last_sync_gmt'] = gmdate('Y-m-d H:i:s', (int) $state['started_epoch']);
                    if (!empty($source_item['force_full_sync'])) {
                        unset($source_item['force_full_sync']);
                    }
                    break;
                }
            }
            unset($source_item);
            update_option(self::OPTION_SOURCES, $source_list, false);
            $state['status'] = 'completed';
            $total_note = isset($state['source_total']) ? sprintf(' تعداد اعلام‌شده توسط API: %d.', (int) $state['source_total']) : '';
            $this->add_log($source_id, 'success', sprintf('همگام‌سازی کامل شد؛ %d جدید، %d به‌روزرسانی، %d ردشده.%s', $state['created'], $state['updated'], $state['skipped'], $total_note));
        } else {
            $state['status'] = 'partial';
            $total_note = isset($state['source_total']) ? sprintf(' تعداد اعلام‌شده توسط API: %d.', (int) $state['source_total']) : '';
            $this->add_log($source_id, 'warning', sprintf('همگام‌سازی با خطا پایان یافت؛ %d جدید، %d به‌روزرسانی، %d ردشده، %d خطا.%s نشانگر آخرین سینک جلو نرفت تا اجرای بعدی دوباره تلاش کند.', $state['created'], $state['updated'], $state['skipped'], $state['errors'], $total_note));
        }
        $state['finished_epoch'] = time();
        $states[$source_id] = $state;
        update_option(self::OPTION_STATES, $states, false);
        return true;
    }

    private function request_api($source, $params = array()) {
        if (empty($source['base_url']) || empty($source['endpoint'])) {
            return new WP_Error('mpsync_api_config', 'آدرس سایت یا مسیر API تنظیم نشده است.');
        }
        $base = untrailingslashit($source['base_url']);
        $endpoint = trim($source['endpoint']);
        if (preg_match('#^https?://#i', $endpoint)) {
            $url = $endpoint;
        } else {
            $url = $base . '/' . ltrim($endpoint, '/');
        }
        $parsed = wp_parse_url($url);
        if (empty($parsed['host']) || !in_array(strtolower(isset($parsed['scheme']) ? $parsed['scheme'] : ''), array('http', 'https'), true)) {
            return new WP_Error('mpsync_invalid_url', 'آدرس API معتبر نیست.');
        }

        // Never send a source's REST API credentials to a different origin.
        // Absolute endpoints are accepted only when they use the same scheme,
        // host, and effective port as the configured source URL.
        $base_parsed = wp_parse_url($base);
        if (preg_match('#^https?://#i', $endpoint)) {
            $base_scheme = strtolower(isset($base_parsed['scheme']) ? $base_parsed['scheme'] : '');
            $endpoint_scheme = strtolower(isset($parsed['scheme']) ? $parsed['scheme'] : '');
            $base_host = strtolower(isset($base_parsed['host']) ? $base_parsed['host'] : '');
            $endpoint_host = strtolower(isset($parsed['host']) ? $parsed['host'] : '');
            $base_port = isset($base_parsed['port']) ? (int) $base_parsed['port'] : ('https' === $base_scheme ? 443 : 80);
            $endpoint_port = isset($parsed['port']) ? (int) $parsed['port'] : ('https' === $endpoint_scheme ? 443 : 80);
            if ($base_scheme !== $endpoint_scheme || $base_host !== $endpoint_host || $base_port !== $endpoint_port) {
                return new WP_Error('mpsync_endpoint_origin_mismatch', 'مسیر کامل API باید روی همان دامنه و درگاه منبع تنظیم‌شده باشد تا کلیدهای دسترسی به دامنه دیگری ارسال نشوند.');
            }
        }

        $host = strtolower($parsed['host']);
        $is_local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
        if ('https' !== strtolower($parsed['scheme']) && !$is_local) {
            return new WP_Error('mpsync_https_required', 'برای ارسال امن Consumer Key و Secret، آدرس سایت مبدأ باید HTTPS باشد.');
        }
        if (empty($source['consumer_key']) || empty($source['consumer_secret'])) {
            return new WP_Error('mpsync_missing_credentials', 'Consumer Key و Consumer Secret تنظیم نشده‌اند.');
        }
        $url = add_query_arg($params, $url);
        $auth = base64_encode($source['consumer_key'] . ':' . $source['consumer_secret']);
        $response = wp_remote_get($url, array(
            'timeout' => 45,
            'redirection' => 3,
            'sslverify' => true,
            'headers' => array(
                'Authorization' => 'Basic ' . $auth,
                'Accept' => 'application/json',
                'User-Agent' => 'Toranide-Product-Sync/' . self::VERSION . '; ' . home_url('/'),
            ),
        ));
        if (is_wp_error($response)) {
            return new WP_Error('mpsync_http_error', 'خطای ارتباط با API: ' . $response->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = 'پاسخ API با کد HTTP ' . $status . ' برگشت.';
            if (is_array($decoded) && !empty($decoded['message'])) {
                $message .= ' ' . sanitize_text_field($decoded['message']);
            }
            if (401 === $status || 403 === $status) {
                $message .= ' دسترسی و مجوز Read کلیدهای API را بررسی کنید.';
            }
            return new WP_Error('mpsync_api_http_' . $status, $message);
        }
        if (!is_array($decoded)) {
            return new WP_Error('mpsync_bad_json', 'پاسخ API JSON معتبر نیست.');
        }
        // A product list is a numerically indexed array; WordPress REST error objects are associative.
        if (isset($decoded['code']) && isset($decoded['message'])) {
            return new WP_Error('mpsync_api_error', sanitize_text_field($decoded['message']));
        }
        if (array() !== $decoded && array_keys($decoded) !== range(0, count($decoded) - 1)) {
            return new WP_Error('mpsync_unexpected_response', 'ساختار پاسخ API فهرست محصولات ووکامرس نیست. مسیر API محصولات را بررسی کنید.');
        }
        $total_pages = (int) wp_remote_retrieve_header($response, 'x-wp-totalpages');
        $total_items = (int) wp_remote_retrieve_header($response, 'x-wp-total');
        return array('products' => $decoded, 'total_pages' => $total_pages, 'total_items' => $total_items);
    }

    private function import_product($source, $remote, $sync_variations = false, $variation_context = null) {
        $remote_type = is_array($variation_context) ? 'variation' : (isset($remote['type']) ? sanitize_key($remote['type']) : 'simple');
        $product_classes = array(
            'simple' => 'WC_Product_Simple',
            'variable' => 'WC_Product_Variable',
            'variation' => 'WC_Product_Variation',
            'external' => 'WC_Product_External',
            'grouped' => 'WC_Product_Grouped',
        );
        if (!isset($product_classes[$remote_type]) || !class_exists($product_classes[$remote_type])) {
            $this->add_log(isset($source['id']) ? $source['id'] : '', 'warning', 'محصول مبدأ #' . (isset($remote['id']) ? absint($remote['id']) : 'نامشخص') . ' با نوع «' . sanitize_text_field($remote_type) . '» پشتیبانی نمی‌شود و رد شد.');
            return 'skipped';
        }
        $remote_api_id = (string) absint($remote['id']);
        $remote_id = is_array($variation_context) ? 'variation:' . $remote_api_id : $remote_api_id;
        $source_id = (string) $source['id'];
        $existing_id = $this->find_imported_product($source_id, $remote_id);
        $legacy_match_method = '';
        if (!$existing_id) {
            // Adopt products that were previously imported with WooCommerce's CSV importer.
            // Their local IDs differ from the source IDs and they do not yet have our tracking metadata.
            $legacy_match = $this->find_legacy_imported_product($source_id, $remote, $remote_type, $variation_context);
            if (!empty($legacy_match['ambiguous'])) {
                $name = isset($remote['name']) ? sanitize_text_field((string) $remote['name']) : ('#' . $remote_api_id);
                $this->add_log($source_id, 'warning', 'محصول «' . $name . '» به دلیل وجود چند محصول قدیمی مشابه به‌صورت خودکار به‌روزرسانی نشد؛ برای جلوگیری از اتصال اشتباه یا تکراری‌سازی، شناسه/SKU یا نامک را بررسی کنید.');
                return 'skipped';
            }
            if (!empty($legacy_match['id'])) {
                $existing_id = absint($legacy_match['id']);
                $legacy_match_method = isset($legacy_match['method']) ? (string) $legacy_match['method'] : 'legacy';
                $this->add_log($source_id, 'info', 'محصول قدیمی مقصد #' . $existing_id . ' با روش «' . $legacy_match_method . '» به محصول مبدأ #' . $remote_api_id . ' متصل شد و به‌روزرسانی می‌شود.');
            }
        }
        $product_class = $product_classes[$remote_type];
        $product = $existing_id ? wc_get_product($existing_id) : new $product_class();
        if (!$product) {
            return new WP_Error('mpsync_product_load', 'محصول مقصد موجود قابل بارگذاری نیست.');
        }
        if ($existing_id && get_class($product) !== $product_class && !is_a($product, $product_class)) {
            return new WP_Error('mpsync_product_type', 'نوع محصول مبدأ با نوع محصول قبلاً واردشده فرق دارد؛ برای جلوگیری از خرابی، نوع محصول خودکار عوض نشد.');
        }

        $mapping = isset($source['mapping']) && is_array($source['mapping']) ? $source['mapping'] : $this->default_mapping();
        $mapped = array();
        $custom_meta = array();
        $taxonomy_values = array();
        $attribute_specs = array();
        $attribute_defaults = array();
        $variation_attribute_values = array();
        $image_override = null;
        $allowed = $this->supported_product_fields();

        foreach ($mapping as $row) {
            if (!is_array($row) || empty($row['target'])) {
                continue;
            }
            $target = trim((string) $row['target']);
            $mode = isset($row['mode']) ? $row['mode'] : 'source';
            $path = isset($row['source']) ? trim((string) $row['source']) : '';
            $default = isset($row['value']) ? $row['value'] : '';
            $found = false;
            $value = null;
            if ('static' !== $mode && '' !== $path) {
                $value = $this->get_path_value($remote, $path, $found);
            }
            if ('static' === $mode) {
                $found = true;
                $value = $default;
            } elseif ('fallback' === $mode && (!$found || '' === $value || null === $value || (is_array($value) && empty($value)))) {
                $found = true;
                $value = $default;
            }
            if (!$found) {
                continue;
            }
            if (0 === strpos($target, 'meta:')) {
                $key = sanitize_key(substr($target, 5));
                if ('' !== $key) {
                    $custom_meta[$key] = $value;
                }
            } elseif (0 === strpos($target, 'taxonomy:')) {
                $taxonomy = sanitize_key(substr($target, 9));
                if ('' !== $taxonomy) {
                    $taxonomy_values[$taxonomy] = $this->normalize_terms($value);
                }
            } elseif ('images' === $target) {
                $image_override = $value;
            } elseif ('attributes' === $target) {
                $mapped['attributes'] = is_array($variation_context)
                    ? $this->normalize_variation_attributes($value)
                    : $this->normalize_product_attributes($value);
            } elseif ('default_attributes' === $target && !is_array($variation_context)) {
                $mapped['default_attributes'] = $this->normalize_default_attributes($value);
            } elseif (preg_match('/^attribute:([^:]+)$/i', $target, $attribute_match)) {
                $attribute_name = trim($attribute_match[1]);
                if ('' !== $attribute_name) {
                    if (is_array($variation_context)) {
                        $terms = $this->normalize_terms($value);
                        $variation_attribute_values[$attribute_name] = $terms ? $terms[0] : (is_scalar($value) ? (string) $value : '');
                    } else {
                        $attribute_specs[$attribute_name]['options'] = $this->normalize_terms($value);
                    }
                }
            } elseif (preg_match('/^attribute_default:([^:]+)$/i', $target, $attribute_match)) {
                $attribute_defaults[trim($attribute_match[1])] = is_scalar($value) ? (string) $value : '';
            } elseif (preg_match('/^attribute_data:([^:]+)$/i', $target, $attribute_match)) {
                $attribute_name = trim($attribute_match[1]);
                if (is_array($value)) {
                    $attribute_specs[$attribute_name] = array_merge(isset($attribute_specs[$attribute_name]) ? $attribute_specs[$attribute_name] : array(), $value);
                } elseif (is_scalar($value)) {
                    $bits = array_map('trim', explode('|', (string) $value));
                    $attribute_specs[$attribute_name]['position'] = isset($bits[0]) ? absint($bits[0]) : 0;
                    $attribute_specs[$attribute_name]['visible'] = isset($bits[1]) ? $this->to_bool($bits[1]) : true;
                    $attribute_specs[$attribute_name]['variation'] = isset($bits[2]) ? $this->to_bool($bits[2]) : false;
                    if (isset($bits[3]) && '' !== $bits[3]) {
                        $attribute_specs[$attribute_name]['is_taxonomy'] = $this->to_bool($bits[3]);
                    }
                }
            } elseif ('shipping_class' === $target) {
                $taxonomy_values['product_shipping_class'] = $this->normalize_terms($value);
            } elseif (in_array($target, array('upsell_ids', 'cross_sell_ids', 'children', 'parent_id'), true)) {
                $mapped[$target] = $value; // resolved to destination IDs below; source IDs are never copied as destination IDs.
            } elseif (in_array($target, $allowed, true)) {
                $mapped[$target] = $value;
            }
        }

        if (is_array($variation_context) && $variation_attribute_values) {
            $explicit_variation_values = $this->normalize_variation_attributes_from_map($variation_attribute_values);
            $mapped['attributes'] = array_merge(isset($mapped['attributes']) && is_array($mapped['attributes']) ? $mapped['attributes'] : array(), $explicit_variation_values);
        }
        if ($attribute_specs) {
            $extra_attributes = $this->build_attributes_from_specs($attribute_specs);
            if (isset($mapped['attributes']) && is_array($mapped['attributes'])) {
                $existing_attrs = array();
                foreach ($mapped['attributes'] as $attr) {
                    if ($attr instanceof WC_Product_Attribute) {
                        $existing_attrs[strtolower($attr->get_name())] = $attr;
                    }
                }
                foreach ($extra_attributes as $attr) {
                    $existing_attrs[strtolower($attr->get_name())] = $attr;
                }
                $mapped['attributes'] = array_values($existing_attrs);
            } else {
                $mapped['attributes'] = $extra_attributes;
            }
        }
        if ($attribute_defaults && !is_array($variation_context)) {
            $mapped['default_attributes'] = array_merge(
                isset($mapped['default_attributes']) && is_array($mapped['default_attributes']) ? $mapped['default_attributes'] : array(),
                $this->normalize_default_attributes($attribute_defaults)
            );
        }
        foreach (array('upsell_ids', 'cross_sell_ids', 'children', 'parent_id') as $relation_field) {
            if (array_key_exists($relation_field, $mapped)) {
                $mapped[$relation_field] = $this->resolve_remote_product_references($source_id, $mapped[$relation_field], 'parent_id' === $relation_field);
                if (null === $mapped[$relation_field]) {
                    unset($mapped[$relation_field]);
                }
            }
        }
        if (is_array($variation_context)) {
            $mapped['parent_id'] = absint($variation_context['parent_local_id']);
        }

        $price_multiplier = isset($source['price_multiplier']) && is_numeric($source['price_multiplier'])
            ? (float) $source['price_multiplier'] : 1.0;
        $price_multiplier = max(0.01, min(100.0, $price_multiplier));
        if (1.0 !== $price_multiplier) {
            foreach (array('regular_price', 'sale_price') as $price_field) {
                if (isset($mapped[$price_field]) && is_scalar($mapped[$price_field]) && '' !== (string) $mapped[$price_field] && is_numeric($mapped[$price_field])) {
                    $mapped[$price_field] = wc_format_decimal((float) $mapped[$price_field] * $price_multiplier, wc_get_price_decimals());
                }
            }
        }

        if ('variation' !== $remote_type && (empty($mapped['name']) || !is_scalar($mapped['name']) || '' === trim((string) $mapped['name']))) {
            return new WP_Error('mpsync_missing_name', 'نگاشت فیلد name مقدار معتبر ندارد؛ برای محصول نام الزامی است.');
        }
        if (isset($mapped['status']) && !in_array($mapped['status'], array('publish', 'draft', 'pending', 'private'), true)) {
            $mapped['status'] = 'publish';
        }
        if (isset($mapped['stock_status']) && !in_array($mapped['stock_status'], array('instock', 'outofstock', 'onbackorder'), true)) {
            unset($mapped['stock_status']);
        }
        if (isset($mapped['catalog_visibility']) && !in_array($mapped['catalog_visibility'], array('visible', 'catalog', 'search', 'hidden'), true)) {
            unset($mapped['catalog_visibility']);
        }
        if (isset($mapped['tax_status']) && !in_array($mapped['tax_status'], array('taxable', 'shipping', 'none'), true)) {
            unset($mapped['tax_status']);
        }
        if (isset($mapped['backorders']) && !in_array($mapped['backorders'], array('no', 'notify', 'yes'), true)) {
            unset($mapped['backorders']);
        }

        foreach ($mapped as $field => $value) {
            $setter = 'set_' . $field;
            if ('shipping_class' === $field) {
                continue; // assigned by destination taxonomy term after save.
            }
            if (!method_exists($product, $setter)) {
                continue;
            }
            if ('downloads' === $field) {
                $value = $this->normalize_downloads($value, $source_id, $remote_id);
            } elseif ('attributes' === $field) {
                if (!is_array($value)) {
                    continue;
                }
            } elseif (in_array($field, array('upsell_ids', 'cross_sell_ids', 'children'), true)) {
                $value = array_values(array_filter(array_map('absint', (array) $value)));
            } elseif ('parent_id' === $field) {
                $value = absint($value);
            } elseif (in_array($field, array('featured', 'virtual', 'downloadable', 'manage_stock', 'sold_individually', 'reviews_allowed'), true)) {
                $value = $this->to_bool($value);
            } elseif ('stock_quantity' === $field) {
                if ('' === $value || null === $value || !is_numeric($value)) {
                    continue;
                }
                $value = wc_stock_amount($value);
            } elseif (in_array($field, array('regular_price', 'sale_price', 'weight', 'length', 'width', 'height'), true)) {
                if (null !== $value && '' !== $value && is_scalar($value)) {
                    $value = wc_format_decimal((string) $value);
                } elseif (in_array($field, array('regular_price', 'sale_price'), true)) {
                    $value = '';
                } else {
                    continue;
                }
            } elseif ('low_stock_amount' === $field || 'download_limit' === $field || 'download_expiry' === $field || 'menu_order' === $field) {
                if ('' === $value || null === $value || !is_numeric($value)) {
                    continue;
                }
                $value = absint($value);
            } elseif (in_array($field, array('name', 'slug', 'description', 'short_description', 'sku', 'status', 'stock_status', 'catalog_visibility', 'tax_status', 'tax_class', 'purchase_note', 'backorders', 'product_url', 'button_text', 'global_unique_id', 'customs_commodity_code', 'customs_country_of_origin', 'customs_description'), true)) {
                if (is_array($value) || is_object($value)) {
                    continue;
                }
                $value = (string) $value;
            }
            try {
                $product->{$setter}($value);
            } catch (Throwable $error) {
                return new WP_Error('mpsync_invalid_field_' . $field, 'مقدار فیلد ' . $field . ' معتبر نیست: ' . $error->getMessage());
            }
        }

        // Target WooCommerce product IDs are assigned by WordPress. We track each imported product by source UUID + remote product ID.
        $desired_sku = method_exists($product, 'get_sku') ? (string) $product->get_sku('edit') : '';
        $unique_sku = $this->make_unique_sku($desired_sku, $source_id, $remote_id, $existing_id);
        $product->set_sku($unique_sku);
        $product->update_meta_data('_mpsync_source_id', $source_id);
        $product->update_meta_data('_mpsync_remote_product_id', $remote_id);
        $product->update_meta_data('_mpsync_source_product_url', isset($remote['permalink']) ? esc_url_raw($remote['permalink']) : '');
        if (isset($remote['date_modified_gmt'])) {
            $product->update_meta_data('_mpsync_source_modified_gmt', sanitize_text_field($remote['date_modified_gmt']));
        }
        foreach ($custom_meta as $meta_key => $meta_value) {
            $product->update_meta_data($meta_key, $meta_value);
        }

        try {
            $product_id = $product->save();
        } catch (Throwable $error) {
            return new WP_Error('mpsync_product_save', 'ذخیره محصول شکست خورد: ' . $error->getMessage());
        }
        if (!$product_id) {
            return new WP_Error('mpsync_product_save', 'ووکامرس شناسه‌ای برای محصول مقصد برنگرداند.');
        }
        foreach ($taxonomy_values as $taxonomy => $terms) {
            if (!$terms || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $tax_obj = get_taxonomy($taxonomy);
            if (!$tax_obj || !in_array('product', (array) $tax_obj->object_type, true)) {
                continue;
            }
            $result = wp_set_object_terms($product_id, $terms, $taxonomy, false);
            if (is_wp_error($result)) {
                $this->add_log($source_id, 'warning', 'محصول مقصد #' . $product_id . ': انتساب taxonomy ' . $taxonomy . ' ناموفق بود: ' . $result->get_error_message());
            }
        }
        if (!empty($source['sync_images'])) {
            $image_result = $this->sync_product_images($product_id, $remote, $image_override);
            if (is_wp_error($image_result)) {
                $this->add_log($source_id, 'warning', 'تصاویر محصول مقصد #' . $product_id . ': ' . $image_result->get_error_message());
            }
        }
        if ('variable' === $remote_type && $sync_variations && !is_array($variation_context)) {
            $variation_result = $this->sync_variations($source, $remote, $product_id);
            if (is_wp_error($variation_result)) {
                return $variation_result;
            }
        }
        return $existing_id ? 'updated' : 'created';
    }

    private function sync_variations($source, $remote_parent, $local_parent_id) {
        $parent_remote_id = isset($remote_parent['id']) ? absint($remote_parent['id']) : 0;
        if (!$parent_remote_id || !$local_parent_id) {
            return new WP_Error('mpsync_variation_parent', 'شناسه محصول متغیر مبدأ یا مقصد معتبر نیست.');
        }
        $variation_source = $source;
        $endpoint = trim((string) $variation_source['endpoint']);
        $endpoint = preg_replace('/\?.*$/', '', $endpoint);
        $variation_source['endpoint'] = untrailingslashit($endpoint) . '/' . $parent_remote_id . '/variations';
        $page = 1;
        $created = 0;
        $updated = 0;
        do {
            $response = $this->request_api($variation_source, array('page' => $page, 'per_page' => 100, 'status' => 'any'));
            if (is_wp_error($response)) {
                return new WP_Error('mpsync_variations_api', 'واکشی وارییشن‌های محصول #' . $parent_remote_id . ' ناموفق بود: ' . $response->get_error_message());
            }
            $variations = is_array($response['products']) ? $response['products'] : array();
            foreach ($variations as $variation) {
                if (!is_array($variation) || empty($variation['id'])) {
                    continue;
                }
                $result = $this->import_product($source, $variation, false, array(
                    'parent_local_id' => absint($local_parent_id),
                    'parent_remote_id' => $parent_remote_id,
                ));
                if (is_wp_error($result)) {
                    $this->add_log($source['id'], 'error', 'وارییشن مبدأ #' . absint($variation['id']) . ' از والد #' . $parent_remote_id . ': ' . $result->get_error_message());
                    return $result;
                }
                if ('created' === $result) {
                    $created++;
                } elseif ('updated' === $result) {
                    $updated++;
                }
            }
            $pages = isset($response['total_pages']) ? (int) $response['total_pages'] : 0;
            $has_more = $pages > 0 ? ($page < $pages) : (count($variations) >= 100);
            $page++;
        } while ($has_more);
        $this->add_log($source['id'], 'info', sprintf('محصول متغیر مقصد #%d: %d وارییشن جدید، %d وارییشن به‌روزرسانی شد.', absint($local_parent_id), $created, $updated));
        return true;
    }

    private function normalize_default_attributes($value) {
        if (!is_array($value)) {
            return array();
        }
        $defaults = array();
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $name = isset($item['name']) ? sanitize_text_field($item['name']) : (is_string($key) ? sanitize_text_field($key) : '');
                $option = isset($item['option']) ? $item['option'] : (isset($item['value']) ? $item['value'] : '');
                $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($name) : '';
                $is_global = !empty($item['id']) && $taxonomy && taxonomy_exists($taxonomy);
                $attribute_key = $is_global ? $taxonomy : sanitize_title($name);
            } else {
                $name = is_string($key) ? sanitize_text_field($key) : '';
                $option = $item;
                $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($name) : '';
                $is_global = $taxonomy && taxonomy_exists($taxonomy);
                $attribute_key = $is_global ? $taxonomy : sanitize_title($name);
            }
            if ('' === $attribute_key || !is_scalar($option) || '' === trim((string) $option)) {
                continue;
            }
            $defaults[$attribute_key] = $this->variation_option_value($attribute_key, (string) $option);
        }
        return $defaults;
    }

    private function normalize_variation_attributes($value) {
        if (!is_array($value)) {
            return array();
        }
        $attributes = array();
        foreach ($value as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $name = sanitize_text_field($item['name']);
            $option = isset($item['option']) ? $item['option'] : (isset($item['options']) ? $item['options'] : '');
            if (is_array($option)) {
                $option = reset($option);
            }
            if (!is_scalar($option) || '' === trim((string) $option)) {
                continue;
            }
            $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($name) : '';
            $is_global = !empty($item['id']) && $taxonomy && taxonomy_exists($taxonomy);
            $key = $is_global ? $taxonomy : sanitize_title($name);
            $attributes[$key] = $this->variation_option_value($key, (string) $option);
        }
        return $attributes;
    }

    private function normalize_variation_attributes_from_map($value) {
        $attributes = array();
        foreach ((array) $value as $name => $option) {
            if (!is_scalar($option) || '' === trim((string) $option)) {
                continue;
            }
            $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($name) : '';
            $is_global = $taxonomy && taxonomy_exists($taxonomy);
            $key = $is_global ? $taxonomy : sanitize_title($name);
            $attributes[$key] = $this->variation_option_value($key, (string) $option);
        }
        return $attributes;
    }

    private function variation_option_value($taxonomy_or_name, $option) {
        if (taxonomy_exists($taxonomy_or_name)) {
            $term = get_term_by('name', $option, $taxonomy_or_name);
            if ($term && !is_wp_error($term)) {
                return (string) $term->slug;
            }
        }
        return sanitize_title($option);
    }

    private function normalize_product_attributes($value) {
        if (!is_array($value)) {
            return array();
        }
        $attributes = array();
        foreach ($value as $position => $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }
            $name = sanitize_text_field($item['name']);
            $taxonomy = function_exists('wc_attribute_taxonomy_name') ? wc_attribute_taxonomy_name($name) : '';
            // The REST API uses a positive attribute ID for global attributes
            // and id=0 for product-specific attributes. Prefer that explicit signal
            // over guessing from the destination taxonomy name, which may exist
            // even when the source attribute is local/custom.
            if (array_key_exists('id', $item)) {
                $is_global = absint($item['id']) > 0 && $taxonomy && taxonomy_exists($taxonomy);
            } elseif (isset($item['is_taxonomy'])) {
                $is_global = $this->to_bool($item['is_taxonomy']) && $taxonomy && taxonomy_exists($taxonomy);
            } else {
                $is_global = $taxonomy && taxonomy_exists($taxonomy);
            }
            $attribute = new WC_Product_Attribute();
            if ($is_global) {
                $attribute_id = function_exists('wc_attribute_taxonomy_id_by_name') ? absint(wc_attribute_taxonomy_id_by_name($taxonomy)) : 0;
                if ($attribute_id) {
                    $attribute->set_id($attribute_id);
                }
                $attribute->set_name($taxonomy);
                $option_names = $this->normalize_terms(isset($item['options']) ? $item['options'] : array());
                $option_ids = array();
                foreach ($option_names as $option_name) {
                    $term = term_exists($option_name, $taxonomy);
                    if (!$term) {
                        $created = wp_insert_term($option_name, $taxonomy);
                        if (!is_wp_error($created)) {
                            $term = $created;
                        }
                    }
                    if (is_array($term) && !empty($term['term_id'])) {
                        $option_ids[] = absint($term['term_id']);
                    } elseif (is_numeric($term)) {
                        $option_ids[] = absint($term);
                    }
                }
                $attribute->set_options($option_ids);
            } else {
                $attribute->set_name($name);
                $attribute->set_options($this->normalize_terms(isset($item['options']) ? $item['options'] : array()));
            }
            $attribute->set_position(isset($item['position']) ? absint($item['position']) : absint($position));
            $attribute->set_visible(isset($item['visible']) ? $this->to_bool($item['visible']) : true);
            $attribute->set_variation(isset($item['variation']) ? $this->to_bool($item['variation']) : false);
            $attributes[] = $attribute;
        }
        return $attributes;
    }

    private function build_attributes_from_specs($specs) {
        $items = array();
        foreach ($specs as $name => $spec) {
            if ('' === trim((string) $name)) {
                continue;
            }
            $data = array(
                'name' => $name,
                'options' => isset($spec['options']) ? $spec['options'] : array(),
                'position' => isset($spec['position']) ? absint($spec['position']) : count($items),
                'visible' => isset($spec['visible']) ? $this->to_bool($spec['visible']) : true,
                'variation' => isset($spec['variation']) ? $this->to_bool($spec['variation']) : false,
                'is_taxonomy' => isset($spec['is_taxonomy']) ? $this->to_bool($spec['is_taxonomy']) : null,
            );
            if (null === $data['is_taxonomy']) {
                unset($data['is_taxonomy']);
            }
            $items[] = $data;
        }
        return $this->normalize_product_attributes($items);
    }

    private function normalize_downloads($value, $source_id, $remote_id) {
        if (!is_array($value) || !class_exists('WC_Product_Download')) {
            return array();
        }
        $downloads = array();
        foreach ($value as $index => $item) {
            if (is_string($item)) {
                $item = array('file' => $item, 'name' => basename((string) parse_url($item, PHP_URL_PATH)));
            }
            if (!is_array($item)) {
                continue;
            }
            $file = isset($item['file']) ? esc_url_raw($item['file']) : (isset($item['url']) ? esc_url_raw($item['url']) : '');
            if ('' === $file) {
                continue;
            }
            $download = new WC_Product_Download();
            $download->set_id(md5($source_id . '|' . $remote_id . '|download|' . $index . '|' . $file));
            $download->set_name(isset($item['name']) ? sanitize_text_field($item['name']) : basename((string) parse_url($file, PHP_URL_PATH)));
            $download->set_file($file);
            if (isset($item['file'])) {
                $downloads[] = $download;
            }
        }
        return $downloads;
    }

    private function resolve_remote_product_references($source_id, $value, $single = false) {
        $refs = is_array($value) ? $value : array($value);
        $local_ids = array();
        foreach ($refs as $ref) {
            if (is_array($ref)) {
                $ref = isset($ref['id']) ? $ref['id'] : (isset($ref['remote_id']) ? $ref['remote_id'] : '');
            }
            if (!is_scalar($ref) || '' === trim((string) $ref)) {
                continue;
            }
            $local_id = $this->find_imported_product((string) $source_id, (string) absint($ref));
            if ($local_id) {
                $local_ids[] = absint($local_id);
            }
        }
        $local_ids = array_values(array_unique($local_ids));
        if ($single) {
            return $local_ids ? $local_ids[0] : null;
        }
        return $local_ids;
    }

    /**
     * Find an existing product imported before this plugin was installed.
     * Matching order: exact SKU, exact global unique ID, exact slug, then a unique exact title.
     * Variations are matched by SKU or by their attribute combination under the matched parent.
     * Products already managed by a different source are never adopted.
     */
    private function find_legacy_imported_product($source_id, $remote, $remote_type, $variation_context = null) {
        $source_id = (string) $source_id;
        $remote_type = (string) $remote_type;
        $post_type = 'variation' === $remote_type ? 'product_variation' : 'product';
        $statuses = array('publish', 'pending', 'draft', 'private', 'future');

        $sku = isset($remote['sku']) && is_scalar($remote['sku']) ? trim((string) $remote['sku']) : '';
        if ('' !== $sku && function_exists('wc_get_product_id_by_sku')) {
            $id = absint(wc_get_product_id_by_sku($sku));
            if ($id && $this->legacy_candidate_is_eligible($id, $source_id, $remote_type, $variation_context)) {
                return array('id' => $id, 'method' => 'SKU');
            }
        }

        $global_unique_id = isset($remote['global_unique_id']) && is_scalar($remote['global_unique_id'])
            ? trim((string) $remote['global_unique_id']) : '';
        if ('' !== $global_unique_id) {
            $ids = get_posts(array(
                'post_type' => $post_type,
                'post_status' => $statuses,
                'fields' => 'ids',
                'posts_per_page' => 20,
                'orderby' => 'ID',
                'order' => 'ASC',
                'meta_key' => '_global_unique_id',
                'meta_value' => $global_unique_id,
                'suppress_filters' => true,
            ));
            $match = $this->choose_legacy_candidate($ids, $source_id, $remote_type, $variation_context);
            if (!empty($match['id']) || !empty($match['ambiguous'])) {
                $match['method'] = 'شناسه جهانی کالا (GTIN/UPC/EAN/ISBN)';
                return $match;
            }
        }

        if ('variation' === $remote_type && is_array($variation_context) && !empty($variation_context['parent_local_id'])) {
            $remote_attributes = isset($remote['attributes']) && is_array($remote['attributes'])
                ? $this->normalize_variation_attributes($remote['attributes']) : array();
            $remote_signature = $this->legacy_variation_signature($remote_attributes);
            if ('' !== $remote_signature) {
                $ids = get_posts(array(
                    'post_type' => 'product_variation',
                    'post_status' => $statuses,
                    'post_parent' => absint($variation_context['parent_local_id']),
                    'fields' => 'ids',
                    'posts_per_page' => -1,
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                ));
                $matching_ids = array();
                foreach ($ids as $id) {
                    $id = absint($id);
                    if (!$this->legacy_candidate_is_eligible($id, $source_id, $remote_type, $variation_context)) {
                        continue;
                    }
                    $candidate = wc_get_product($id);
                    if ($candidate && $remote_signature === $this->legacy_variation_signature($candidate->get_attributes())) {
                        $matching_ids[] = $id;
                    }
                }
                if (1 === count($matching_ids)) {
                    return array('id' => $matching_ids[0], 'method' => 'ترکیب ویژگی‌های وارییشن زیر محصول والد');
                }
                if (count($matching_ids) > 1) {
                    return array('id' => 0, 'ambiguous' => true, 'method' => 'ویژگی‌های وارییشن');
                }
            }
            // Variation titles/slugs are not reliable identifiers; do not guess when SKU/attributes fail.
            return array('id' => 0, 'ambiguous' => false);
        }

        $slug = isset($remote['slug']) && is_scalar($remote['slug']) ? sanitize_title((string) $remote['slug']) : '';
        if ('' !== $slug) {
            $ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => $statuses,
                'fields' => 'ids',
                'posts_per_page' => 30,
                'orderby' => 'ID',
                'order' => 'ASC',
                'name' => $slug,
                'suppress_filters' => true,
            ));
            $match = $this->choose_legacy_candidate($ids, $source_id, $remote_type, null);
            if (!empty($match['id']) || !empty($match['ambiguous'])) {
                $match['method'] = 'نامک (slug)';
                return $match;
            }
        }

        $title = isset($remote['name']) && is_scalar($remote['name']) ? trim(wp_strip_all_tags((string) $remote['name'])) : '';
        if ('' !== $title) {
            $ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => $statuses,
                'fields' => 'ids',
                'posts_per_page' => 50,
                'orderby' => 'ID',
                'order' => 'ASC',
                'title' => $title,
                'suppress_filters' => true,
            ));
            $match = $this->choose_legacy_candidate($ids, $source_id, $remote_type, null);
            if (!empty($match['id']) || !empty($match['ambiguous'])) {
                $match['method'] = 'نام دقیق و یکتای محصول';
                return $match;
            }
        }

        return array('id' => 0, 'ambiguous' => false);
    }

    private function choose_legacy_candidate($ids, $source_id, $remote_type, $variation_context = null) {
        $eligible = array();
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if ($id && $this->legacy_candidate_is_eligible($id, $source_id, $remote_type, $variation_context)) {
                $eligible[] = $id;
            }
        }
        $eligible = array_values(array_unique($eligible));
        if (1 === count($eligible)) {
            return array('id' => $eligible[0], 'ambiguous' => false);
        }
        if (count($eligible) > 1) {
            return array('id' => 0, 'ambiguous' => true);
        }
        return array('id' => 0, 'ambiguous' => false);
    }

    private function legacy_candidate_is_eligible($product_id, $source_id, $remote_type, $variation_context = null) {
        $owner_source = get_post_meta($product_id, '_mpsync_source_id', true);
        if ('' !== (string) $owner_source && (string) $owner_source !== (string) $source_id) {
            return false;
        }
        $product = wc_get_product($product_id);
        if (!$product || (string) $product->get_type() !== (string) $remote_type) {
            return false;
        }
        if ('variation' === $remote_type && is_array($variation_context) && !empty($variation_context['parent_local_id'])) {
            if ((int) $product->get_parent_id() !== (int) $variation_context['parent_local_id']) {
                return false;
            }
        }
        return true;
    }

    private function legacy_variation_signature($attributes) {
        if (!is_array($attributes) || !$attributes) {
            return '';
        }
        $normalized = array();
        foreach ($attributes as $name => $value) {
            if (is_array($value)) {
                $value = reset($value);
            }
            if (!is_scalar($name) || !is_scalar($value) || '' === trim((string) $value)) {
                continue;
            }
            $key = strtolower(trim((string) $name));
            $key = preg_replace('/^attribute[_-]/', '', $key);
            $normalized[$key] = strtolower(sanitize_title((string) $value));
        }
        if (!$normalized) {
            return '';
        }
        ksort($normalized);
        return md5(wp_json_encode($normalized));
    }

    private function find_imported_product($source_id, $remote_id) {
        $ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => array('publish', 'pending', 'draft', 'private', 'future', 'trash'),
            'fields' => 'ids',
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_mpsync_source_id', 'value' => (string) $source_id, 'compare' => '='),
                array('key' => '_mpsync_remote_product_id', 'value' => (string) $remote_id, 'compare' => '='),
            ),
            'suppress_filters' => true,
        ));
        return !empty($ids) ? absint($ids[0]) : 0;
    }

    private function make_unique_sku($desired, $source_id, $remote_id, $current_product_id = 0) {
        $desired = is_scalar($desired) ? trim((string) $desired) : '';
        if ('' !== $desired) {
            $owner_id = wc_get_product_id_by_sku($desired);
            if (!$owner_id || (int) $owner_id === (int) $current_product_id) {
                return $desired;
            }
        }
        $source_token = strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', $source_id), 0, 8));
        if ('' === $source_token) {
            $source_token = 'source';
        }
        $base = 'mps-' . $source_token . '-' . preg_replace('/[^0-9]/', '', (string) $remote_id);
        $base = substr($base, 0, 80);
        $candidate = $base;
        $suffix = 2;
        while (($owner_id = wc_get_product_id_by_sku($candidate)) && (int) $owner_id !== (int) $current_product_id) {
            $candidate = substr($base, 0, 70) . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }

    private function sync_product_images($product_id, $remote, $override = null) {
        $images = (null !== $override) ? $override : (isset($remote['images']) ? $remote['images'] : array());
        if (empty($images) && !empty($remote['image']['src'])) {
            $images = array($remote['image']);
        }
        if (is_string($images)) {
            $images = array_filter(array_map('trim', preg_split('/[|,\n]+/', $images)));
            $images = array_map(function ($url) { return array('src' => $url); }, $images);
        }
        if (empty($images) || !is_array($images)) {
            return true;
        }
        $urls = array();
        foreach ($images as $image) {
            if (is_string($image)) {
                $image = array('src' => $image);
            }
            if (!empty($image['src']) && is_string($image['src'])) {
                $url = esc_url_raw($image['src']);
                if ($url && preg_match('#^https?://#i', $url)) {
                    $urls[] = $url;
                }
            }
        }
        if (!$urls) {
            return true;
        }
        $signature = md5(wp_json_encode($urls));
        if (get_post_meta($product_id, '_mpsync_image_signature', true) === $signature) {
            return true;
        }
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attachment_ids = array();
        foreach ($urls as $url) {
            $existing = get_posts(array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_key' => '_mpsync_source_image_url',
                'meta_value' => $url,
            ));
            if (!empty($existing[0])) {
                $attachment_ids[] = absint($existing[0]);
                continue;
            }
            $image_id = media_sideload_image($url, $product_id, isset($remote['name']) ? sanitize_text_field($remote['name']) : '', 'id');
            if (is_wp_error($image_id)) {
                return new WP_Error('mpsync_image_download', 'دریافت تصویر ناموفق بود (' . esc_url_raw($url) . '): ' . $image_id->get_error_message());
            }
            update_post_meta($image_id, '_mpsync_source_image_url', $url);
            $attachment_ids[] = absint($image_id);
        }
        if ($attachment_ids) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_image_id(array_shift($attachment_ids));
                $product->set_gallery_image_ids(array_values($attachment_ids));
                $product->update_meta_data('_mpsync_image_signature', $signature);
                $product->save();
            }
        }
        return true;
    }

    private function get_path_value($data, $path, &$found = false) {
        $found = false;
        $path = trim((string) $path);
        if ('' === $path) {
            return null;
        }
        $path = preg_replace('/\[(\d+)\]/', '.$1', $path);

        // WooCommerce REST exposes meta_data as a list of {key,value} objects.
        // Allow `meta_data.my_custom_key` to look up a value by the actual meta key,
        // instead of relying on the unstable array index (meta_data.0.value).
        if (is_array($data) && isset($data['meta_data']) && is_array($data['meta_data'])) {
            $meta_parts = explode('.', $path, 2);
            if (isset($meta_parts[0], $meta_parts[1]) && 'meta_data' === $meta_parts[0] && '' !== $meta_parts[1]) {
                $meta_path_parts = explode('.', $meta_parts[1], 2);
                // Keep numeric list-index paths such as meta_data.0.value on the
                // ordinary resolver; nonnumeric paths are interpreted as meta keys.
                if (!ctype_digit($meta_path_parts[0])) {
                    $meta_key = $meta_parts[1];
                    foreach ($data['meta_data'] as $meta_item) {
                        if (is_array($meta_item) && isset($meta_item['key']) && (string) $meta_item['key'] === $meta_key && array_key_exists('value', $meta_item)) {
                            $found = true;
                            return $meta_item['value'];
                        }
                    }
                    return null;
                }
            }
        }

        $parts = explode('.', $path);
        $value = $data;
        foreach ($parts as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->{$part})) {
                $value = $value->{$part};
            } else {
                return null;
            }
        }
        $found = true;
        return $value;
    }

    private function normalize_terms($value) {
        if (is_array($value)) {
            $terms = array();
            foreach ($value as $item) {
                if (is_array($item)) {
                    if (isset($item['name'])) {
                        $terms[] = sanitize_text_field($item['name']);
                    } elseif (isset($item['slug'])) {
                        $terms[] = sanitize_text_field($item['slug']);
                    }
                } elseif (is_scalar($item)) {
                    $terms[] = sanitize_text_field((string) $item);
                }
            }
            return array_values(array_unique(array_filter($terms, 'strlen')));
        }
        if (!is_scalar($value)) {
            return array();
        }
        $terms = array_map('trim', explode(',', (string) $value));
        return array_values(array_unique(array_filter(array_map('sanitize_text_field', $terms), 'strlen')));
    }

    private function to_bool($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (bool) $value;
        }
        return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'true', 'on', 'بله'), true);
    }

    private function supported_product_fields() {
        return array(
            'name', 'slug', 'description', 'short_description', 'sku', 'status', 'featured',
            'catalog_visibility', 'virtual', 'downloadable', 'regular_price', 'sale_price',
            'date_on_sale_from', 'date_on_sale_to', 'tax_status', 'tax_class', 'manage_stock',
            'stock_quantity', 'low_stock_amount', 'stock_status', 'backorders', 'sold_individually',
            'weight', 'length', 'width', 'height', 'purchase_note', 'reviews_allowed', 'menu_order',
            'global_unique_id', 'customs_commodity_code', 'customs_country_of_origin', 'customs_description',
            'download_limit', 'download_expiry', 'downloads', 'attributes',
            'product_url', 'button_text', 'upsell_ids', 'cross_sell_ids', 'children', 'parent_id',
            'shipping_class',
        );
    }

    /**
     * Common WooCommerce CSV importer/exporter fields plus extension points for
     * taxonomy, meta, and product-attribute mappings.
     */
    private function mapping_field_options() {
        return array(
            'مشخصات اصلی (ستون‌های استاندارد CSV ووکامرس)' => array(
                'name' => 'Name — نام محصول',
                'slug' => 'Slug — نامک',
                'status' => 'Published / Status — وضعیت انتشار',
                'featured' => 'Is featured? — محصول ویژه',
                'catalog_visibility' => 'Visibility in catalog — نمایش در کاتالوگ',
                'sku' => 'SKU — شناسه کالا',
                'global_unique_id' => 'GTIN, UPC, EAN, or ISBN — شناسه جهانی کالا',
                'customs_commodity_code' => 'Customs commodity code — کد گمرکی کالا (در نسخه‌های پشتیبان)',
                'customs_country_of_origin' => 'Country of origin — کشور مبدأ (کد دوحرفی ISO)',
                'customs_description' => 'Customs description — توضیحات گمرکی',
                'menu_order' => 'Position — ترتیب نمایش',
                'reviews_allowed' => 'Allow customer reviews? — اجازه ثبت دیدگاه',
                'purchase_note' => 'Purchase note — یادداشت خرید',
            ),
            'توضیحات، قیمت و مالیات' => array(
                'short_description' => 'Short description — توضیح کوتاه',
                'description' => 'Description — توضیحات کامل',
                'regular_price' => 'Regular price — قیمت عادی',
                'sale_price' => 'Sale price — قیمت فروش ویژه',
                'date_on_sale_from' => 'Date sale price starts — شروع تخفیف',
                'date_on_sale_to' => 'Date sale price ends — پایان تخفیف',
                'tax_status' => 'Tax status — وضعیت مالیات',
                'tax_class' => 'Tax class — کلاس مالیاتی',
            ),
            'موجودی و انبار' => array(
                'manage_stock' => 'Manage stock? — مدیریت موجودی',
                'stock_quantity' => 'Stock — تعداد موجودی',
                'low_stock_amount' => 'Low stock amount — آستانه کمبود موجودی',
                'stock_status' => 'In stock? / Stock status — وضعیت موجودی',
                'backorders' => 'Backorders allowed? — پیش‌فروش در نبود موجودی',
                'sold_individually' => 'Sold individually? — فروش تکی',
            ),
            'ابعاد و ارسال' => array(
                'weight' => 'Weight — وزن',
                'length' => 'Length — طول',
                'width' => 'Width — عرض',
                'height' => 'Height — ارتفاع',
                'shipping_class' => 'Shipping class — کلاس حمل‌ونقل',
            ),
            'دسته‌بندی، برچسب و برند' => array(
                'taxonomy:product_cat' => 'Categories — دسته‌بندی‌ها',
                'taxonomy:product_tag' => 'Tags — برچسب‌ها',
                'taxonomy:product_brand' => 'Brands — برند ووکامرس (product_brand)',
                'taxonomy:pwb-brand' => 'Brands — برند Perfect Brands (pwb-brand)',
            ),
            'تصاویر و محصولات دانلودی' => array(
                'images' => 'Images — تصویر شاخص و گالری',
                'virtual' => 'Virtual — محصول مجازی',
                'downloadable' => 'Downloadable — محصول دانلودی',
                'download_limit' => 'Download limit — محدودیت دانلود',
                'download_expiry' => 'Download expiry days — انقضای دانلود (روز)',
                'downloads' => 'Download files — نام و آدرس فایل‌های دانلودی',
            ),
            'محصول خارجی و ارتباط بین محصولات' => array(
                'product_url' => 'External URL — آدرس محصول خارجی',
                'button_text' => 'Button text — متن دکمه محصول خارجی',
                'upsell_ids' => 'Upsells — محصولات افزایشی',
                'cross_sell_ids' => 'Cross-sells — فروش مکمل',
                'children' => 'Grouped products — محصولات گروهی',
                'parent_id' => 'Parent — محصول والد / محصول متغیر',
            ),
            'ویژگی‌های محصول (Attribute columns)' => array(
                'attributes' => 'Attributes — همه ویژگی‌ها از API مبدأ',
                'default_attributes' => 'Attribute defaults — مقادیر پیش‌فرض ویژگی‌های محصول متغیر',
                'attribute:NAME' => 'Attribute N value(s) — مقادیر ویژگی (NAME را جایگزین کنید)',
                'attribute_default:NAME' => 'Attribute N default — مقدار پیش‌فرض ویژگی',
                'attribute_data:NAME' => 'Attribute N position / visible / variation / global — تنظیمات ویژگی',
            ),
            'گزینه‌های توسعه و فیلدهای سفارشی' => array(
                'meta:my_custom_field' => 'Custom metadata (meta:) — فیلد سفارشی با مقدار ثابت یا مبدأ',
                'taxonomy:my_taxonomy' => 'Custom taxonomy — تاکسونومی سفارشی، مانند برند افزونه‌ها',
            ),
        );
    }

    private function default_mapping() {
        // Show every standard WooCommerce product field up front. Empty/missing
        // source paths are ignored at runtime, and each row can be removed or edited.
        return array(
            array('target' => 'name', 'source' => 'name', 'mode' => 'source', 'value' => ''),
            array('target' => 'slug', 'source' => 'slug', 'mode' => 'source', 'value' => ''),
            array('target' => 'status', 'source' => 'status', 'mode' => 'fallback', 'value' => 'publish'),
            array('target' => 'featured', 'source' => 'featured', 'mode' => 'source', 'value' => ''),
            array('target' => 'catalog_visibility', 'source' => 'catalog_visibility', 'mode' => 'source', 'value' => ''),
            array('target' => 'sku', 'source' => 'sku', 'mode' => 'source', 'value' => ''),
            array('target' => 'global_unique_id', 'source' => 'global_unique_id', 'mode' => 'source', 'value' => ''),
            array('target' => 'customs_commodity_code', 'source' => 'customs_commodity_code', 'mode' => 'source', 'value' => ''),
            array('target' => 'customs_country_of_origin', 'source' => 'customs_country_of_origin', 'mode' => 'source', 'value' => ''),
            array('target' => 'customs_description', 'source' => 'customs_description', 'mode' => 'source', 'value' => ''),
            array('target' => 'menu_order', 'source' => 'menu_order', 'mode' => 'source', 'value' => ''),
            array('target' => 'reviews_allowed', 'source' => 'reviews_allowed', 'mode' => 'source', 'value' => ''),
            array('target' => 'purchase_note', 'source' => 'purchase_note', 'mode' => 'source', 'value' => ''),
            array('target' => 'short_description', 'source' => 'short_description', 'mode' => 'source', 'value' => ''),
            array('target' => 'description', 'source' => 'description', 'mode' => 'source', 'value' => ''),
            array('target' => 'regular_price', 'source' => 'regular_price', 'mode' => 'source', 'value' => ''),
            array('target' => 'sale_price', 'source' => 'sale_price', 'mode' => 'source', 'value' => ''),
            array('target' => 'date_on_sale_from', 'source' => 'date_on_sale_from', 'mode' => 'source', 'value' => ''),
            array('target' => 'date_on_sale_to', 'source' => 'date_on_sale_to', 'mode' => 'source', 'value' => ''),
            array('target' => 'tax_status', 'source' => 'tax_status', 'mode' => 'source', 'value' => ''),
            array('target' => 'tax_class', 'source' => 'tax_class', 'mode' => 'source', 'value' => ''),
            array('target' => 'manage_stock', 'source' => 'manage_stock', 'mode' => 'source', 'value' => ''),
            array('target' => 'stock_quantity', 'source' => 'stock_quantity', 'mode' => 'source', 'value' => ''),
            array('target' => 'low_stock_amount', 'source' => 'low_stock_amount', 'mode' => 'source', 'value' => ''),
            array('target' => 'stock_status', 'source' => 'stock_status', 'mode' => 'source', 'value' => ''),
            array('target' => 'backorders', 'source' => 'backorders', 'mode' => 'source', 'value' => ''),
            array('target' => 'sold_individually', 'source' => 'sold_individually', 'mode' => 'source', 'value' => ''),
            array('target' => 'weight', 'source' => 'weight', 'mode' => 'source', 'value' => ''),
            array('target' => 'length', 'source' => 'dimensions.length', 'mode' => 'source', 'value' => ''),
            array('target' => 'width', 'source' => 'dimensions.width', 'mode' => 'source', 'value' => ''),
            array('target' => 'height', 'source' => 'dimensions.height', 'mode' => 'source', 'value' => ''),
            array('target' => 'shipping_class', 'source' => 'shipping_class', 'mode' => 'source', 'value' => ''),
            array('target' => 'taxonomy:product_cat', 'source' => 'categories', 'mode' => 'source', 'value' => ''),
            array('target' => 'taxonomy:product_tag', 'source' => 'tags', 'mode' => 'source', 'value' => ''),
            array('target' => 'taxonomy:product_brand', 'source' => 'brands', 'mode' => 'source', 'value' => ''),
            array('target' => 'images', 'source' => 'images', 'mode' => 'source', 'value' => ''),
            array('target' => 'virtual', 'source' => 'virtual', 'mode' => 'source', 'value' => ''),
            array('target' => 'downloadable', 'source' => 'downloadable', 'mode' => 'source', 'value' => ''),
            array('target' => 'download_limit', 'source' => 'download_limit', 'mode' => 'source', 'value' => ''),
            array('target' => 'download_expiry', 'source' => 'download_expiry', 'mode' => 'source', 'value' => ''),
            array('target' => 'downloads', 'source' => 'downloads', 'mode' => 'source', 'value' => ''),
            array('target' => 'product_url', 'source' => 'external_url', 'mode' => 'source', 'value' => ''),
            array('target' => 'button_text', 'source' => 'button_text', 'mode' => 'source', 'value' => ''),
            array('target' => 'upsell_ids', 'source' => 'upsell_ids', 'mode' => 'source', 'value' => ''),
            array('target' => 'cross_sell_ids', 'source' => 'cross_sell_ids', 'mode' => 'source', 'value' => ''),
            array('target' => 'children', 'source' => 'grouped_products', 'mode' => 'source', 'value' => ''),
            array('target' => 'parent_id', 'source' => 'parent_id', 'mode' => 'source', 'value' => ''),
            array('target' => 'attributes', 'source' => 'attributes', 'mode' => 'source', 'value' => ''),
            array('target' => 'default_attributes', 'source' => 'default_attributes', 'mode' => 'source', 'value' => ''),
        );
    }

    private function encrypt_secret($plain) {
        $plain = (string) $plain;
        if ('' === $plain) {
            return '';
        }
        if (!function_exists('openssl_encrypt') || !function_exists('random_bytes')) {
            return '';
        }
        try {
            $iv = random_bytes(16);
            $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
            $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if (false === $cipher) {
                return '';
            }
            return 'enc:' . base64_encode($iv . $cipher);
        } catch (Throwable $error) {
            return '';
        }
    }

    private function decrypt_secret($stored) {
        $stored = (string) $stored;
        if (0 === strpos($stored, 'plain:')) {
            $decoded = base64_decode(substr($stored, 6), true);
            return false === $decoded ? '' : $decoded;
        }
        if (0 !== strpos($stored, 'enc:')) {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }
        $raw = base64_decode(substr($stored, 4), true);
        if (false === $raw || strlen($raw) < 17) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return false === $plain ? '' : $plain;
    }

    private function add_log($source_id, $level, $message) {
        $source = $this->get_source($source_id);
        $logs = get_option(self::OPTION_LOGS, array());
        if (!is_array($logs)) {
            $logs = array();
        }
        array_unshift($logs, array(
            'time_gmt' => gmdate('Y-m-d H:i:s'),
            'source_id' => (string) $source_id,
            'source_name' => $source && isset($source['name']) ? sanitize_text_field($source['name']) : (string) $source_id,
            'level' => sanitize_key($level),
            'message' => sanitize_text_field($message),
        ));
        $logs = array_slice($logs, 0, 150);
        update_option(self::OPTION_LOGS, $logs, false);
    }

    public function handle_save_source() {
        if (!self::can_manage()) {
            wp_die('شما اجازه انجام این کار را ندارید.');
        }
        check_admin_referer('mpsync_save_source');
        if (!class_exists('WooCommerce')) {
            $this->redirect('missing_woocommerce');
        }
        $posted = isset($_POST['mpsync_source']) && is_array($_POST['mpsync_source']) ? wp_unslash($_POST['mpsync_source']) : array();
        $sources = $this->sources();
        $source_id = isset($posted['id']) ? sanitize_text_field($posted['id']) : '';
        $existing_index = null;
        $existing = null;
        foreach ($sources as $index => $source) {
            if ($source_id && isset($source['id']) && (string) $source['id'] === $source_id) {
                $existing_index = $index;
                $existing = $source;
                break;
            }
        }
        if (!$source_id || !$existing) {
            do {
                $source_id = str_replace('-', '', wp_generate_uuid4());
                $duplicate = false;
                foreach ($sources as $source) {
                    if (isset($source['id']) && (string) $source['id'] === $source_id) {
                        $duplicate = true;
                        break;
                    }
                }
            } while ($duplicate);
        }
        $name = isset($posted['name']) ? sanitize_text_field($posted['name']) : '';
        $base_url = isset($posted['base_url']) ? esc_url_raw(trim($posted['base_url'])) : '';
        $endpoint_raw = isset($posted['endpoint']) ? trim((string) $posted['endpoint']) : 'wp-json/wc/v3/products';
        $endpoint = preg_match('#^https?://#i', $endpoint_raw) ? esc_url_raw($endpoint_raw) : sanitize_text_field(ltrim($endpoint_raw, '/'));
        if ('' === $name || '' === $base_url || '' === $endpoint || !wp_http_validate_url($base_url)) {
            $this->redirect('invalid_source', $source_id);
        }
        $parsed_base = wp_parse_url($base_url);
        if (empty($parsed_base['host']) || !in_array(strtolower(isset($parsed_base['scheme']) ? $parsed_base['scheme'] : ''), array('http', 'https'), true)) {
            $this->redirect('invalid_source', $source_id);
        }
        $consumer_key_input = isset($posted['consumer_key']) ? trim((string) $posted['consumer_key']) : '';
        $consumer_secret_input = isset($posted['consumer_secret']) ? trim((string) $posted['consumer_secret']) : '';
        if (('' !== $consumer_key_input || '' !== $consumer_secret_input) && (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt') || !function_exists('random_bytes'))) {
            $this->redirect('crypto_unavailable', $source_id);
        }
        $consumer_key = '' !== $consumer_key_input ? $this->encrypt_secret($consumer_key_input) : (isset($existing['consumer_key']) ? $existing['consumer_key'] : '');
        $consumer_secret = '' !== $consumer_secret_input ? $this->encrypt_secret($consumer_secret_input) : (isset($existing['consumer_secret']) ? $existing['consumer_secret'] : '');
        if (('' !== $consumer_key_input && '' === $consumer_key) || ('' !== $consumer_secret_input && '' === $consumer_secret)) {
            $this->redirect('crypto_unavailable', $source_id);
        }

        $interval = isset($posted['interval_hours']) ? absint($posted['interval_hours']) : 24;
        $interval = max(1, min(168, $interval));
        $schedule_time = isset($posted['schedule_time']) ? sanitize_text_field($posted['schedule_time']) : '03:00';
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $schedule_time)) {
            $schedule_time = '03:00';
        }
        $price_multiplier_raw = isset($posted['price_multiplier']) ? wc_format_decimal((string) $posted['price_multiplier']) : '1';
        $price_multiplier = is_numeric($price_multiplier_raw) ? (float) $price_multiplier_raw : 1.0;
        $price_multiplier = max(0.01, min(100.0, $price_multiplier));
        $mapping = array();
        if (isset($posted['mapping']) && is_array($posted['mapping'])) {
            foreach ($posted['mapping'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (isset($row['target_choice'])) {
                    $choice = trim(sanitize_text_field($row['target_choice']));
                    $target = ('__custom__' === $choice)
                        ? (isset($row['custom_target']) ? trim(sanitize_text_field($row['custom_target'])) : '')
                        : $choice;
                } else {
                    // Backward compatibility with mappings saved by version 1.0.0.
                    $target = isset($row['target']) ? trim(sanitize_text_field($row['target'])) : '';
                }
                $path = isset($row['source']) ? trim(sanitize_text_field($row['source'])) : '';
                $mode = isset($row['mode']) && in_array($row['mode'], array('source', 'static', 'fallback'), true) ? $row['mode'] : 'source';
                $value = isset($row['value']) ? sanitize_textarea_field($row['value']) : '';
                if ('' === $target) {
                    continue;
                }
                if (0 === strpos($target, 'meta:')) {
                    $key = sanitize_key(substr($target, 5));
                    $target = '' !== $key ? 'meta:' . $key : '';
                } elseif (0 === strpos($target, 'taxonomy:')) {
                    $taxonomy = sanitize_key(substr($target, 9));
                    $target = '' !== $taxonomy ? 'taxonomy:' . $taxonomy : '';
                } elseif (preg_match('/^(attribute|attribute_default|attribute_data):/i', $target)) {
                    list($attribute_prefix, $attribute_name) = array_pad(explode(':', $target, 2), 2, '');
                    $attribute_name = trim(sanitize_text_field($attribute_name));
                    $target = '' !== $attribute_name ? strtolower($attribute_prefix) . ':' . $attribute_name : '';
                } else {
                    $target = sanitize_key($target);
                }
                if ('' === $target) {
                    continue;
                }
                $mapping[] = array('target' => $target, 'source' => $path, 'mode' => $mode, 'value' => $value);
            }
        }
        if (!$mapping) {
            $mapping = $this->default_mapping();
        }
        $source = array(
            'id' => $source_id,
            'name' => $name,
            'base_url' => untrailingslashit($base_url),
            'endpoint' => $endpoint,
            'consumer_key' => $consumer_key,
            'consumer_secret' => $consumer_secret,
            'enabled' => !empty($posted['enabled']) ? 1 : 0,
            'interval_hours' => $interval,
            'schedule_time' => $schedule_time,
            'price_multiplier' => number_format($price_multiplier, 4, '.', ''),
            'sync_images' => !empty($posted['sync_images']) ? 1 : 0,
            'mapping' => $mapping,
            'last_sync_gmt' => isset($existing['last_sync_gmt']) ? $existing['last_sync_gmt'] : '',
            'force_full_sync' => !empty($existing['force_full_sync']) ? 1 : 0,
        );
        if (null === $existing_index) {
            $sources[] = $source;
        } else {
            $sources[$existing_index] = $source;
        }
        update_option(self::OPTION_SOURCES, array_values($sources), false);
        $this->schedule_source($source);
        $this->add_log($source_id, 'info', 'تنظیمات منبع ذخیره شد.');
        $this->redirect('saved', $source_id);
    }

    public function handle_delete_source() {
        if (!self::can_manage()) {
            wp_die('شما اجازه انجام این کار را ندارید.');
        }
        $source_id = isset($_POST['source_id']) ? sanitize_text_field(wp_unslash($_POST['source_id'])) : '';
        check_admin_referer('mpsync_delete_source_' . $source_id);
        $sources = array_values(array_filter($this->sources(), function ($source) use ($source_id) {
            return !isset($source['id']) || (string) $source['id'] !== (string) $source_id;
        }));
        update_option(self::OPTION_SOURCES, $sources, false);
        self::clear_scheduled_actions($source_id);
        $states = $this->states();
        unset($states[$source_id]);
        update_option(self::OPTION_STATES, $states, false);
        $signatures = get_option(self::OPTION_SIGS, array());
        if (is_array($signatures)) {
            unset($signatures[$source_id]);
            update_option(self::OPTION_SIGS, $signatures, false);
        }
        $this->add_log($source_id, 'warning', 'منبع حذف شد. محصولات قبلاً واردشده در سایت مقصد حذف نمی‌شوند.');
        $this->redirect('deleted');
    }

    public function handle_run_now() {
        if (!self::can_manage()) {
            wp_die('شما اجازه انجام این کار را ندارید.');
        }
        $source_id = isset($_POST['source_id']) ? sanitize_text_field(wp_unslash($_POST['source_id'])) : '';
        check_admin_referer('mpsync_run_now_' . $source_id);
        $result = $this->start_sync($source_id, 'manual');
        if (is_wp_error($result)) {
            $this->redirect('sync_error', $source_id);
        }
        $this->redirect('sync_started', $source_id);
    }

    public function handle_test_source() {
        if (!self::can_manage()) {
            wp_die('شما اجازه انجام این کار را ندارید.');
        }
        $source_id = isset($_POST['source_id']) ? sanitize_text_field(wp_unslash($_POST['source_id'])) : '';
        check_admin_referer('mpsync_test_source_' . $source_id);
        $source = $this->get_source($source_id);
        if (!$source) {
            $this->redirect('invalid_source');
        }
        $result = $this->request_api($this->source_for_runtime($source), array('page' => 1, 'per_page' => 1));
        if (is_wp_error($result)) {
            $this->add_log($source_id, 'error', 'تست API ناموفق بود: ' . $result->get_error_message());
            $this->redirect('test_failed', $source_id);
        }
        $this->add_log($source_id, 'success', 'تست API موفق بود. اتصال به فهرست محصولات برقرار است.');
        $this->redirect('test_ok', $source_id);
    }

    private function redirect($notice, $source_id = '') {
        $url = admin_url('admin.php?page=mpsync-marketplace&notice=' . rawurlencode($notice));
        if ('' !== (string) $source_id) {
            $url .= '&source_id=' . rawurlencode((string) $source_id);
        }
        wp_safe_redirect($url);
        exit;
    }

    public function render_admin_page() {
        if (!self::can_manage()) {
            wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
        }
        echo '<div class="wrap mpsync-wrap"><h1>همگام‌سازی محصولات ووکامرس</h1>';
        $this->render_styles();
        $this->render_notice();
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>WooCommerce فعال نیست. افزونه برای ساخت و به‌روزرسانی محصولات به آن نیاز دارد.</p></div></div>';
            return;
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'sources';
        if ('edit' === $tab) {
            $source_id = isset($_GET['source_id']) ? sanitize_text_field(wp_unslash($_GET['source_id'])) : '';
            $source = $source_id ? $this->get_source($source_id) : null;
            $this->render_source_form($source);
        } elseif ('logs' === $tab) {
            $this->render_logs();
        } else {
            $this->render_sources_table();
        }
        echo '</div>';
    }

    private function render_notice() {
        if (empty($_GET['notice'])) {
            return;
        }
        $notice = sanitize_key(wp_unslash($_GET['notice']));
        $messages = array(
            'saved' => array('success', 'تنظیمات منبع ذخیره شد و زمان‌بندی به‌روزرسانی شد.'),
            'deleted' => array('success', 'منبع حذف شد. محصولات قبلی در مقصد باقی می‌مانند.'),
            'invalid_source' => array('error', 'اطلاعات منبع معتبر نیست؛ نام، آدرس پایه و مسیر API را بررسی کنید.'),
            'missing_credentials' => array('error', 'برای منبع جدید باید Consumer Key و Consumer Secret وارد شود.'),
            'crypto_unavailable' => array('error', 'امکان رمزنگاری امن کلیدهای API در این سرور وجود ندارد؛ افزونه OpenSSL در PHP را بررسی کنید.'),
            'missing_woocommerce' => array('error', 'ابتدا WooCommerce را فعال کنید.'),
            'sync_started' => array('success', 'همگام‌سازی شروع شد. اگر محصولات زیادی وجود داشته باشد، صفحات بعدی در پس‌زمینه پردازش می‌شوند.'),
            'sync_error' => array('error', 'همگام‌سازی آغاز نشد. ممکن است منبع در حال اجرا باشد؛ بخش وضعیت یا گزارش را بررسی کنید.'),
            'test_ok' => array('success', 'اتصال API موفق بود.'),
            'test_failed' => array('error', 'تست اتصال شکست خورد. گزارش‌ها را برای جزئیات بررسی کنید.'),
        );
        if (!isset($messages[$notice])) {
            return;
        }
        echo '<div class="notice notice-' . esc_attr($messages[$notice][0]) . ' is-dismissible"><p>' . esc_html($messages[$notice][1]) . '</p></div>';
    }

    private function render_sources_table() {
        $sources = $this->sources();
        echo '<div class="mpsync-toolbar"><div><h2>منابع مبدأ</h2><p>برای هر سایت ووکامرسی یک منبع مستقل تعریف کنید. محصولات جدید و تغییرکرده به‌صورت خودکار شناسایی می‌شوند.</p></div>';
        echo '<a class="button button-primary button-hero" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace&tab=edit')) . '">＋ افزودن منبع جدید</a></div>';
        echo '<nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace')) . '">منابع</a><a class="nav-tab" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace&tab=logs')) . '">گزارش‌ها</a></nav>';
        if (!$sources) {
            echo '<div class="mpsync-empty"><span class="dashicons dashicons-admin-site-alt3"></span><h2>هنوز منبعی تعریف نشده است</h2><p>اولین سایت مبدأ را اضافه کنید تا محصولاتش وارد این فروشگاه شود.</p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace&tab=edit')) . '">افزودن اولین منبع</a></div>';
            return;
        }
        $states = $this->states();
        echo '<div class="mpsync-table-wrap"><table class="widefat striped mpsync-sources"><thead><tr><th>منبع</th><th>API</th><th>زمان‌بندی</th><th>آخرین همگام‌سازی موفق</th><th>وضعیت اجرا</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($sources as $source) {
            $id = isset($source['id']) ? (string) $source['id'] : '';
            $state = isset($states[$id]) && is_array($states[$id]) ? $states[$id] : array();
            $status_class = !empty($source['enabled']) ? 'mpsync-pill-on' : 'mpsync-pill-off';
            $status_text = !empty($source['enabled']) ? 'فعال' : 'غیرفعال';
            $last = !empty($source['last_sync_gmt']) ? get_date_from_gmt($source['last_sync_gmt'], get_option('date_format') . ' ' . get_option('time_format')) : 'هنوز اجرا نشده';
            $run_text = isset($state['status']) ? $this->state_label($state) : 'در انتظار اجرا';
            echo '<tr><td><strong>' . esc_html(isset($source['name']) ? $source['name'] : 'بدون نام') . '</strong><br><span class="mpsync-muted">' . esc_html($id) . '</span><br><span class="mpsync-pill ' . esc_attr($status_class) . '">' . esc_html($status_text) . '</span></td>';
            echo '<td><code>' . esc_html($this->display_endpoint($source)) . '</code><br><span class="mpsync-muted">کلیدها: ' . esc_html(!empty($source['consumer_key']) && !empty($source['consumer_secret']) && 0 === strpos($source['consumer_key'], 'enc:') && 0 === strpos($source['consumer_secret'], 'enc:') ? 'ثبت‌شده و رمزنگاری‌شده' : 'ناقص یا نیازمند بازبینی') . '</span></td>';
            echo '<td>هر ' . esc_html((string) (isset($source['interval_hours']) ? (int) $source['interval_hours'] : 24)) . ' ساعت<br><span class="mpsync-muted">ساعت مبنا: ' . esc_html(isset($source['schedule_time']) ? $source['schedule_time'] : '03:00') . ' (' . esc_html(wp_timezone_string()) . ')</span></td>';
            echo '<td>' . esc_html($last) . '</td><td>' . esc_html($run_text);
            if (isset($state['created']) || isset($state['updated'])) {
                echo '<br><span class="mpsync-muted">جدید: ' . absint(isset($state['created']) ? $state['created'] : 0) . ' · به‌روزرسانی: ' . absint(isset($state['updated']) ? $state['updated'] : 0) . ' · ردشده: ' . absint(isset($state['skipped']) ? $state['skipped'] : 0) . ' · خطا: ' . absint(isset($state['errors']) ? $state['errors'] : 0) . '</span>';
                if (isset($state['source_total'])) {
                    echo '<br><span class="mpsync-muted">تعداد اعلام‌شده از API: ' . absint($state['source_total']) . '</span>';
                }
            }
            if (!empty($state['last_error']) && in_array($state['status'], array('failed', 'partial'), true)) {
                echo '<br><span class="mpsync-error-text">' . esc_html($state['last_error']) . '</span>';
            }
            echo '</td><td><div class="mpsync-actions">';
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace&tab=edit&source_id=' . rawurlencode($id))) . '">ویرایش</a>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="mpsync_run_now"><input type="hidden" name="source_id" value="' . esc_attr($id) . '">';
            wp_nonce_field('mpsync_run_now_' . $id);
            echo '<button type="submit" class="button button-primary">همین الان اجرا کن</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="mpsync_test_source"><input type="hidden" name="source_id" value="' . esc_attr($id) . '">';
            wp_nonce_field('mpsync_test_source_' . $id);
            echo '<button type="submit" class="button">تست API</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'منبع حذف شود؟ محصولات واردشده قبلی حذف نمی‌شوند.\');">';
            echo '<input type="hidden" name="action" value="mpsync_delete_source"><input type="hidden" name="source_id" value="' . esc_attr($id) . '">';
            wp_nonce_field('mpsync_delete_source_' . $id);
            echo '<button type="submit" class="button mpsync-button-danger">حذف</button></form>';
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="mpsync-footnote"><strong>توجه:</strong> شناسه محصول در مقصد را خود ووکامرس به‌صورت یکتا می‌سازد. افزونه برای اتصال پایدار، شناسه داخلی منبع و شناسه محصول مبدأ را جداگانه ذخیره می‌کند؛ بنابراین یک شناسه یکسان در دو سایت مبدأ باعث تداخل نمی‌شود. اگر SKU تکراری باشد، SKU یکتا برای محصول واردشده تولید می‌شود.</div>';
    }

    private function display_endpoint($source) {
        $base = isset($source['base_url']) ? $source['base_url'] : '';
        $endpoint = isset($source['endpoint']) ? $source['endpoint'] : '';
        if (preg_match('#^https?://#i', $endpoint)) {
            return $endpoint;
        }
        return untrailingslashit($base) . '/' . ltrim($endpoint, '/');
    }

    private function state_label($state) {
        $labels = array(
            'running' => 'در حال اجرا… صفحه ' . absint(isset($state['page']) ? $state['page'] : 1),
            'completed' => 'کامل شد',
            'partial' => 'پایان با خطا',
            'failed' => 'ناموفق',
        );
        $status = isset($state['status']) ? $state['status'] : '';
        return isset($labels[$status]) ? $labels[$status] : 'در انتظار اجرا';
    }

    private function render_source_form($source = null) {
        $is_edit = is_array($source) && !empty($source['id']);
        if (!$is_edit) {
            $source = array(
                'id' => '', 'name' => '', 'base_url' => '', 'endpoint' => 'wp-json/wc/v3/products',
                'consumer_key' => '', 'consumer_secret' => '', 'enabled' => 1, 'interval_hours' => 24,
                'schedule_time' => '03:00', 'price_multiplier' => '1.00', 'sync_images' => 1, 'mapping' => $this->default_mapping(),
            );
        }
        $mapping = isset($source['mapping']) && is_array($source['mapping']) ? $source['mapping'] : $this->default_mapping();
        $title = $is_edit ? 'ویرایش منبع' : 'افزودن منبع جدید';
        echo '<nav class="nav-tab-wrapper"><a class="nav-tab" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace')) . '">منابع</a><a class="nav-tab" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace&tab=logs')) . '">گزارش‌ها</a></nav>';
        echo '<div class="mpsync-form-heading"><div><h2>' . esc_html($title) . '</h2><p>این تنظیمات فقط روی سایت مقصد ذخیره می‌شوند. کلید API مبدأ برای خواندن محصولات استفاده می‌شود.</p></div><a class="button" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace')) . '">بازگشت به منابع</a></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mpsync-source-form">';
        echo '<input type="hidden" name="action" value="mpsync_save_source">';
        echo '<input type="hidden" name="mpsync_source[id]" value="' . esc_attr($source['id']) . '">';
        wp_nonce_field('mpsync_save_source');
        echo '<section class="mpsync-panel"><h3>۱. اتصال به سایت مبدأ</h3><div class="mpsync-form-grid">';
        $this->form_input('نام منبع', 'mpsync_source[name]', isset($source['name']) ? $source['name'] : '', 'مثلاً تأمین‌کننده الف', true);
        $this->form_input('آدرس پایه سایت', 'mpsync_source[base_url]', isset($source['base_url']) ? $source['base_url'] : '', 'https://source-site.com', true, 'آدرس اصلی سایت؛ بدون مسیر API و بدون اسلش انتهایی. HTTPS توصیه می‌شود.');
        $this->form_input('مسیر API محصولات', 'mpsync_source[endpoint]', isset($source['endpoint']) ? $source['endpoint'] : 'wp-json/wc/v3/products', 'wp-json/wc/v3/products', true, 'برای ووکامرس معمولاً همین مقدار کافی است. در صورت نیاز می‌توانید مسیر کامل API را وارد کنید.');
        $this->form_input('Consumer Key', 'mpsync_source[consumer_key]', '', !empty($source['consumer_key']) ? 'ثبت شده؛ برای تغییر، کلید جدید وارد کنید' : 'ck_…', !$is_edit, 'کلید API با مجوز Read بسازید. اگر این فیلد را در ویرایش خالی بگذارید، مقدار قبلی حفظ می‌شود.');
        $this->form_input('Consumer Secret', 'mpsync_source[consumer_secret]', '', !empty($source['consumer_secret']) ? 'ثبت شده؛ برای تغییر، Secret جدید وارد کنید' : 'cs_…', !$is_edit, 'Secret در صفحه نمایش داده نمی‌شود و در پایگاه داده رمزنگاری‌شده ذخیره می‌شود.');
        echo '</div><div class="mpsync-checkbox-row"><label><input type="checkbox" name="mpsync_source[enabled]" value="1" ' . checked(!empty($source['enabled']), true, false) . '> منبع فعال باشد و خودکار اجرا شود</label><label><input type="checkbox" name="mpsync_source[sync_images]" value="1" ' . checked(!empty($source['sync_images']), true, false) . '> دانلود تصویر شاخص و گالری در مقصد</label></div>';
        echo '</section>';
        echo '<section class="mpsync-panel"><h3>۲. زمان‌بندی و قیمت</h3><div class="mpsync-form-grid">';
        echo '<div class="mpsync-field"><label for="mpsync_interval">اجرا هر چند ساعت؟</label><input id="mpsync_interval" type="number" min="1" max="168" name="mpsync_source[interval_hours]" value="' . esc_attr(isset($source['interval_hours']) ? (int) $source['interval_hours'] : 24) . '" required><p class="description">مثلاً ۲۴ یعنی هر روز یک‌بار؛ مقدار مجاز ۱ تا ۱۶۸ ساعت.</p></div>';
        echo '<div class="mpsync-field"><label for="mpsync_schedule_time">ساعت مبنا</label><input id="mpsync_schedule_time" type="time" name="mpsync_source[schedule_time]" value="' . esc_attr(isset($source['schedule_time']) ? $source['schedule_time'] : '03:00') . '" required><p class="description">زمان بر اساس منطقه زمانی وردپرس است: ' . esc_html(wp_timezone_string()) . '.</p></div>';
        echo '<div class="mpsync-field"><label for="mpsync_price_multiplier">ضریب قیمت</label><input id="mpsync_price_multiplier" type="number" min="0.01" max="100" step="0.01" name="mpsync_source[price_multiplier]" value="' . esc_attr(isset($source['price_multiplier']) ? $source['price_multiplier'] : '1.00') . '" required><p class="description">۱ یعنی بدون تغییر؛ ۱٫۱۵ یعنی افزایش ۱۵٪ روی قیمت عادی و تخفیف محصول و وارییشن‌ها.</p></div>';
        echo '</div><div class="mpsync-callout">زمان‌بندی خودکار به کمک Action Scheduler ووکامرس انجام می‌شود. اجرای دستی از دکمه «همین الان اجرا کن» مستقل است. اجرای زمان‌بندی‌شده به بازدید سایت هم وابستگی کامل ندارد، اما برای اجرای قابل اتکای صف‌ها بهتر است Cron هاست فعال باشد.</div></section>';
        echo '<section class="mpsync-panel"><div class="mpsync-mapping-heading"><div><h3>۳. نگاشت کامل فیلدهای محصول</h3><p>فهرست فیلدهای رایج خروجی/ورودی CSV ووکامرس به‌همراه ویژگی‌ها، فیلدهای سفارشی و تاکسونومی‌ها در دسترس است. مسیر مبدأ از JSON API خوانده می‌شود؛ مانند <code>name</code>، <code>regular_price</code>، <code>images.0.src</code> و <code>attributes.0.options.0</code>.</p></div><button type="button" class="button button-primary" id="mpsync-add-mapping">＋ افزودن فیلد</button></div>';
        echo '<div class="mpsync-callout"><strong>روش کار:</strong> فیلد مقصد را از فهرست انتخاب کن؛ مسیر سمت مبدأ همان مسیر JSON REST API است. برای برند ثابت، مقصد <code>taxonomy:product_brand</code>، روش «مقدار ثابت» و مقدار «نام برند» را انتخاب کن. برای فیلد سفارشی از <code>meta:your_key</code> و برای تاکسونومی اختصاصی از <code>taxonomy:your_taxonomy</code> استفاده کن. ستون ID عمداً مقصد قابل‌انتخاب نیست: وردپرس باید شناسه مقصد را بسازد؛ افزونه رابطه محصول را با شناسه منبع + شناسه مبدأ ذخیره می‌کند. نوع محصول نیز از <code>type</code> دریافتی API تعیین می‌شود. محصولات ساده، خارجی، گروهی و متغیر پردازش می‌شوند. برای اطمینان از دریافت تغییراتی که فقط در یک وارییشن رخ می‌دهد، همه والدهای متغیر در مرحله دوم بررسی می‌شوند؛ این مرحله درخواست API بیشتری نسبت به سینک صرفاً افزایشی ایجاد می‌کند.</div>';
        echo '<datalist id="mpsync-source-paths">';
        foreach (array('id','type','name','slug','status','featured','catalog_visibility','description','short_description','sku','global_unique_id','customs_commodity_code','customs_country_of_origin','customs_description','regular_price','sale_price','date_on_sale_from','date_on_sale_to','date_on_sale_from_gmt','date_on_sale_to_gmt','tax_status','tax_class','manage_stock','stock_quantity','low_stock_amount','stock_status','backorders','sold_individually','weight','dimensions.length','dimensions.width','dimensions.height','reviews_allowed','purchase_note','categories','categories.0.name','categories.0.slug','categories.0.parent','tags','tags.0.name','tags.0.slug','brands','brands.0.name','brands.0.slug','shipping_class','shipping_class_id','images','images.0.src','images.0.alt','downloadable','virtual','download_limit','download_expiry','downloads','downloads.0.name','downloads.0.file','downloads.0.id','attributes','attributes.0.name','attributes.0.options','attributes.0.options.0','attributes.0.visible','attributes.0.variation','external_url','button_text','upsell_ids','cross_sell_ids','grouped_products','parent_id','permalink','date_created','date_modified','date_modified_gmt','meta_data.0.key','meta_data.0.value','meta_data.my_custom_field') as $source_path) {
            echo '<option value="' . esc_attr($source_path) . '"></option>';
        }
        echo '</datalist>';
        echo '<div class="mpsync-table-wrap"><table class="widefat mpsync-mapping-table"><thead><tr><th>فیلد مقصد (مشابه ووکامرس)</th><th>مسیر فیلد مبدأ / JSON</th><th>روش مقداردهی</th><th>مقدار ثابت / پیش‌فرض</th><th></th></tr></thead><tbody id="mpsync-mapping-rows">';
        foreach ($mapping as $index => $row) {
            $this->render_mapping_row($index, $row);
        }
        echo '</tbody></table></div>';
        echo '<template id="mpsync-map-template">';
        $this->render_mapping_row('__INDEX__', array('target' => '', 'source' => '', 'mode' => 'source', 'value' => ''));
        echo '</template>';
        echo '<div class="mpsync-callout"><strong>روش «مبدأ؛ وگرنه پیش‌فرض»:</strong> اگر مسیر مبدأ خالی، ناموجود یا یک آرایه خالی باشد، مقدار پیش‌فرض استفاده می‌شود. برای ویژگی سفارشی از مقصد <code>attribute:Color</code> و مسیر مبدأ <code>attributes.0.options</code> استفاده کن؛ برای پیش‌فرض همان ویژگی مقصد <code>attribute_default:Color</code> را انتخاب کن. تنظیمات ویژگی به صورت <code>position|visible|variation|global</code> وارد می‌شوند، مثلاً <code>0|1|0|1</code>. برای مقادیر تاکسونومی، نام ترم مبدأ/ثابت در سایت مقصد پیدا یا ایجاد می‌شود؛ شناسه عددی ترم از سایت مبدأ به مقصد منتقل نمی‌شود.</div>';
        echo '</section><div class="mpsync-submit-row"><button type="submit" class="button button-primary button-hero">ذخیره منبع</button><a class="button button-large" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace')) . '">انصراف</a></div></form>';
        echo '<script>(function(){var add=document.getElementById("mpsync-add-mapping");var body=document.getElementById("mpsync-mapping-rows");var template=document.getElementById("mpsync-map-template");if(!add||!body||!template)return;function toggleCustom(row){var select=row.querySelector(".mpsync-target-choice");var custom=row.querySelector(".mpsync-custom-target");if(select&&custom){custom.style.display=select.value==="__custom__"?"block":"none";}}body.querySelectorAll("tr").forEach(toggleCustom);body.addEventListener("change",function(e){if(e.target.matches(".mpsync-target-choice"))toggleCustom(e.target.closest("tr"));});add.addEventListener("click",function(){var index=Date.now().toString()+Math.floor(Math.random()*1000);var html=template.innerHTML.replace(/__INDEX__/g,index);var wrapper=document.createElement("tbody");wrapper.innerHTML=html.trim();if(wrapper.firstElementChild){body.appendChild(wrapper.firstElementChild);toggleCustom(wrapper.firstElementChild);}});body.addEventListener("click",function(e){var button=e.target.closest(".mpsync-remove-row");if(button){e.preventDefault();var row=button.closest("tr");if(row)row.remove();}});})();</script>';
    }

    private function form_input($label, $name, $value, $placeholder = '', $required = false, $description = '') {
        $id = 'mpsync-' . sanitize_key(str_replace(array('[', ']'), '-', $name));
        echo '<div class="mpsync-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . ($required ? ' <span class="required">*</span>' : '') . '</label>';
        echo '<input id="' . esc_attr($id) . '" type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" ' . ($required ? 'required' : '') . '>';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
    }

    private function render_mapping_row($index, $row) {
        $target = isset($row['target']) ? (string) $row['target'] : '';
        $source = isset($row['source']) ? (string) $row['source'] : '';
        $mode = isset($row['mode']) ? $row['mode'] : 'source';
        $value = isset($row['value']) ? $row['value'] : '';
        $options = $this->mapping_field_options();
        $template_targets = array('meta:my_custom_field', 'taxonomy:my_taxonomy', 'attribute:NAME', 'attribute_default:NAME', 'attribute_data:NAME');
        $is_known = false;
        if (!in_array($target, $template_targets, true)) {
            foreach ($options as $group_options) {
                if (array_key_exists($target, $group_options)) {
                    $is_known = true;
                    break;
                }
            }
        }
        $select_value = $is_known ? $target : '__custom__';
        $custom_value = $is_known ? '' : $target;
        $i = esc_attr((string) $index);
        echo '<tr><td class="mpsync-target-cell"><select class="mpsync-target-choice" name="mpsync_source[mapping][' . $i . '][target_choice]" aria-label="فیلد مقصد">';
        foreach ($options as $group => $group_options) {
            echo '<optgroup label="' . esc_attr($group) . '">';
            foreach ($group_options as $option_value => $option_label) {
                echo '<option value="' . esc_attr($option_value) . '" ' . selected($select_value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
            }
            echo '</optgroup>';
        }
        echo '<option value="__custom__" ' . selected($select_value, '__custom__', false) . '>فیلد یا تاکسونومی دلخواه…</option>';
        echo '</select><input class="mpsync-custom-target" type="text" name="mpsync_source[mapping][' . $i . '][custom_target]" value="' . esc_attr($custom_value) . '" placeholder="meta:my_key یا taxonomy:my_taxonomy" aria-label="فیلد مقصد دلخواه" style="margin-top:6px;width:100%;' . ($is_known ? 'display:none;' : '') . '"></td>';
        echo '<td><input type="text" list="mpsync-source-paths" dir="ltr" name="mpsync_source[mapping][' . $i . '][source]" value="' . esc_attr($source) . '" placeholder="name / regular_price" aria-label="مسیر مبدأ"></td>';
        echo '<td><select name="mpsync_source[mapping][' . $i . '][mode]" aria-label="روش مقداردهی">';
        echo '<option value="source" ' . selected($mode, 'source', false) . '>از مبدأ</option>';
        echo '<option value="static" ' . selected($mode, 'static', false) . '>مقدار ثابت</option>';
        echo '<option value="fallback" ' . selected($mode, 'fallback', false) . '>مبدأ؛ وگرنه پیش‌فرض</option>';
        echo '</select></td>';
        echo '<td><textarea name="mpsync_source[mapping][' . $i . '][value]" rows="2" placeholder="مقدار ثابت یا پیش‌فرض" aria-label="مقدار ثابت یا پیش‌فرض">' . esc_textarea($value) . '</textarea></td>';
        echo '<td><button type="button" class="button mpsync-remove-row" aria-label="حذف نگاشت">حذف</button></td></tr>';
    }

    private function render_logs() {
        echo '<nav class="nav-tab-wrapper"><a class="nav-tab" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace')) . '">منابع</a><a class="nav-tab nav-tab-active" href="' . esc_url(admin_url('admin.php?page=mpsync-marketplace&tab=logs')) . '">گزارش‌ها</a></nav>';
        $logs = get_option(self::OPTION_LOGS, array());
        if (!is_array($logs)) {
            $logs = array();
        }
        echo '<div class="mpsync-form-heading"><div><h2>گزارش اجرای همگام‌سازی</h2><p>آخرین ۱۵۰ رویداد ذخیره می‌شود.</p></div></div>';
        echo '<div class="mpsync-table-wrap"><table class="widefat striped"><thead><tr><th>زمان</th><th>منبع</th><th>سطح</th><th>پیام</th></tr></thead><tbody>';
        if (!$logs) {
            echo '<tr><td colspan="4">هنوز گزارشی ثبت نشده است.</td></tr>';
        } else {
            foreach ($logs as $log) {
                $time = !empty($log['time_gmt']) ? get_date_from_gmt($log['time_gmt'], get_option('date_format') . ' ' . get_option('time_format')) : '—';
                $level = isset($log['level']) ? $log['level'] : 'info';
                echo '<tr><td>' . esc_html($time) . '</td><td>' . esc_html(isset($log['source_name']) ? $log['source_name'] : '') . '</td><td><span class="mpsync-pill mpsync-level-' . esc_attr($level) . '">' . esc_html($this->log_level_label($level)) . '</span></td><td>' . esc_html(isset($log['message']) ? $log['message'] : '') . '</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    private function log_level_label($level) {
        $labels = array('info' => 'اطلاعات', 'success' => 'موفق', 'warning' => 'هشدار', 'error' => 'خطا');
        return isset($labels[$level]) ? $labels[$level] : 'اطلاعات';
    }

    private function render_styles() {
        echo '<style>
        .mpsync-wrap{direction:rtl;color:#1d2939}.mpsync-wrap h1{font-size:25px;margin-bottom:22px}.mpsync-toolbar,.mpsync-form-heading,.mpsync-mapping-heading{display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap;margin:24px 0 16px}.mpsync-toolbar h2,.mpsync-form-heading h2,.mpsync-panel h3{margin:0 0 8px}.mpsync-toolbar p,.mpsync-form-heading p,.mpsync-mapping-heading p{margin:0;color:#667085}.mpsync-table-wrap{overflow-x:auto;background:#fff;border:1px solid #dfe3e8;border-radius:8px;margin-top:18px}.mpsync-wrap table{border:0}.mpsync-sources td,.mpsync-sources th{padding:13px 12px;vertical-align:top}.mpsync-sources code{direction:ltr;display:inline-block;max-width:260px;overflow-wrap:anywhere;white-space:normal}.mpsync-muted{font-size:11px;color:#667085}.mpsync-pill{display:inline-block;border-radius:20px;padding:3px 9px;font-size:11px;font-weight:600;margin-top:5px;background:#eef2f6;color:#475467}.mpsync-pill-on,.mpsync-level-success{background:#dcfae6;color:#067647}.mpsync-pill-off{background:#f2f4f7;color:#475467}.mpsync-level-error{background:#fee4e2;color:#b42318}.mpsync-level-warning{background:#fef0c7;color:#93370d}.mpsync-actions{display:flex;gap:6px;flex-wrap:wrap;min-width:240px}.mpsync-actions form{margin:0}.mpsync-button-danger{color:#b42318!important;border-color:#fda29b!important}.mpsync-error-text{display:inline-block;max-width:270px;color:#b42318;font-size:11px;overflow-wrap:anywhere;margin-top:5px}.mpsync-footnote,.mpsync-callout{background:#f8fafc;border:1px solid #e4e7ec;border-right:4px solid #84adff;padding:13px 16px;margin-top:18px;border-radius:6px;line-height:1.9;color:#475467}.mpsync-empty{text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:12px;padding:50px 20px;margin-top:24px}.mpsync-empty .dashicons{font-size:38px;width:38px;height:38px;color:#64748b}.mpsync-empty p{color:#667085}.mpsync-panel{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:24px;margin:18px 0}.mpsync-panel h3{font-size:16px;padding-bottom:14px;border-bottom:1px solid #eaecf0}.mpsync-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 24px;margin-top:20px}.mpsync-field{min-width:0}.mpsync-field label{display:block;font-weight:600;margin-bottom:7px}.mpsync-field .required{color:#d92d20}.mpsync-field input[type=text],.mpsync-field input[type=number],.mpsync-field input[type=time]{width:100%;max-width:none;min-height:40px;border-color:#d0d5dd;border-radius:6px;direction:ltr;text-align:left;padding:6px 10px}.mpsync-field input[name="mpsync_source[name]"]{direction:rtl;text-align:right}.mpsync-field .description{color:#667085;line-height:1.7;margin:6px 0 0}.mpsync-checkbox-row{display:flex;gap:24px;flex-wrap:wrap;margin-top:24px;padding-top:18px;border-top:1px solid #eaecf0}.mpsync-checkbox-row label{display:flex;gap:8px;align-items:center}.mpsync-checkbox-row input{margin:0}.mpsync-mapping-table{min-width:850px}.mpsync-mapping-table th,.mpsync-mapping-table td{padding:8px;vertical-align:middle}.mpsync-mapping-table input,.mpsync-mapping-table select,.mpsync-mapping-table textarea{width:100%;max-width:none;box-sizing:border-box}.mpsync-mapping-table input,.mpsync-mapping-table select{min-height:36px}.mpsync-mapping-table textarea{min-height:46px;resize:vertical}.mpsync-mapping-table td:first-child{min-width:180px}.mpsync-mapping-table td:nth-child(2){min-width:190px}.mpsync-mapping-table td:nth-child(3){min-width:155px}.mpsync-mapping-table td:nth-child(4){min-width:230px}.mpsync-mapping-table td:last-child{min-width:60px}.mpsync-mapping-heading{margin:0 0 10px}.mpsync-mapping-heading h3{border:0;padding:0}.mpsync-mapping-heading p{margin-top:7px}.mpsync-callout code{direction:ltr;unicode-bidi:isolate}.mpsync-submit-row{display:flex;gap:10px;align-items:center;margin:24px 0}.mpsync-source-form .button-hero{padding:0 24px}.mpsync-wrap .nav-tab-wrapper{margin-top:20px}.mpsync-wrap input,.mpsync-wrap textarea,.mpsync-wrap select{box-shadow:none}.mpsync-wrap input:focus,.mpsync-wrap textarea:focus,.mpsync-wrap select:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:0}@media(max-width:782px){.mpsync-form-grid{grid-template-columns:1fr}.mpsync-panel{padding:16px}.mpsync-actions{min-width:230px}.mpsync-toolbar .button-hero{width:100%;text-align:center}.mpsync-submit-row{flex-wrap:wrap}}
        </style>';
    }
}

MPSync_Marketplace_Product_Sync::instance();
