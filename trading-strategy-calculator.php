<?php
/**
 * Plugin Name: Trading Strategy Calculator
 * Description: Displays trading strategy results with an interactive chart and profitability calculator via shortcode [trading_strategy_calculator]
 * Version: 1.0.0
 * Author: Chart Home
 * Text Domain: trading-strategy-calc
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TSC_VERSION', '1.0.0');

class Trading_Strategy_Calculator {

    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'trading_strategy_data';

        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_tsc_upload_excel', [$this, 'handle_upload']);
        add_action('wp_ajax_tsc_get_data', [$this, 'ajax_get_data']);
        add_action('wp_ajax_nopriv_tsc_get_data', [$this, 'ajax_get_data']);
        add_shortcode('trading_strategy_calculator', [$this, 'render_shortcode']);
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            date_value DATE NOT NULL,
            date_label VARCHAR(20) NOT NULL,
            mcftr DECIMAL(20,6) NOT NULL,
            mcftr_pct DECIMAL(10,4) DEFAULT NULL,
            sc_top10 DECIMAL(20,6) NOT NULL,
            sc_top10_pct DECIMAL(10,4) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY date_value (date_value)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Trading Strategy',
            'Trading Strategy',
            'manage_options',
            'trading-strategy-calc',
            [$this, 'render_admin_page'],
            'dashicons-chart-line',
            30
        );
    }

    public function render_admin_page() {
        global $wpdb;
        $row_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $last_date = $wpdb->get_var("SELECT date_label FROM {$this->table_name} ORDER BY date_value DESC LIMIT 1");

        $message = '';
        if (isset($_GET['tsc_message'])) {
            $code = sanitize_text_field($_GET['tsc_message']);
            if ($code === 'success') {
                $imported = isset($_GET['tsc_count']) ? intval($_GET['tsc_count']) : 0;
                $message = '<div class="notice notice-success"><p>Данные успешно загружены. Импортировано строк: ' . $imported . '</p></div>';
            } elseif ($code === 'error') {
                $error = isset($_GET['tsc_error']) ? urldecode($_GET['tsc_error']) : 'Неизвестная ошибка';
                $message = '<div class="notice notice-error"><p>Ошибка: ' . esc_html($error) . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1>Trading Strategy Calculator</h1>
            <?php echo $message; ?>

            <div class="card" style="max-width:600px;padding:20px;">
                <h2>Загрузка данных из Excel</h2>
                <p>Загрузите файл .xlsx с данными торговой стратегии. Текущие данные будут полностью перезаписаны.</p>
                <p><strong>Требуемые столбцы:</strong> DATE, MCFTR, MCFTR в %, SC TOP 10, SC TOP 10 в %</p>

                <?php if ($row_count > 0): ?>
                <div style="background:#f0f0f1;padding:10px 15px;margin-bottom:15px;border-left:4px solid #2271b1;">
                    <p style="margin:0;">Загружено строк: <strong><?php echo esc_html($row_count); ?></strong>
                    <?php if ($last_date): ?> | Последняя дата: <strong><?php echo esc_html($last_date); ?></strong><?php endif; ?></p>
                </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('tsc_upload_excel', 'tsc_nonce'); ?>
                    <input type="hidden" name="action" value="tsc_upload_excel">
                    <p>
                        <input type="file" name="tsc_excel_file" accept=".xlsx" required>
                    </p>
                    <p>
                        <input type="submit" class="button button-primary" value="Загрузить файл">
                    </p>
                </form>
            </div>

            <div class="card" style="max-width:600px;padding:20px;margin-top:20px;">
                <h2>Шорткод</h2>
                <p>Используйте шорткод для отображения графика и калькулятора на странице:</p>
                <code style="display:block;padding:10px;background:#f0f0f1;">[trading_strategy_calculator]</code>
            </div>
        </div>
        <?php
    }

    public function handle_upload() {
        if (!current_user_can('manage_options')) {
            wp_die('Недостаточно прав');
        }

        if (!isset($_POST['tsc_nonce']) || !wp_verify_nonce($_POST['tsc_nonce'], 'tsc_upload_excel')) {
            wp_die('Ошибка безопасности');
        }

        $redirect_url = admin_url('admin.php?page=trading-strategy-calc');

        if (!isset($_FILES['tsc_excel_file']) || $_FILES['tsc_excel_file']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect($redirect_url . '&tsc_message=error&tsc_error=' . urlencode('Ошибка загрузки файла'));
            exit;
        }

        $file = $_FILES['tsc_excel_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== 'xlsx') {
            wp_redirect($redirect_url . '&tsc_message=error&tsc_error=' . urlencode('Допускаются только файлы .xlsx'));
            exit;
        }

        require_once TSC_PLUGIN_DIR . 'includes/class-xlsx-parser.php';

        try {
            $parser = new TSC_XLSX_Parser($file['tmp_name']);
            $rows = $parser->parse();
        } catch (Exception $e) {
            wp_redirect($redirect_url . '&tsc_message=error&tsc_error=' . urlencode($e->getMessage()));
            exit;
        }

        if (empty($rows)) {
            wp_redirect($redirect_url . '&tsc_message=error&tsc_error=' . urlencode('Файл не содержит данных'));
            exit;
        }

        global $wpdb;

        $wpdb->query("TRUNCATE TABLE {$this->table_name}");

        $count = 0;
        foreach ($rows as $row) {
            $wpdb->insert(
                $this->table_name,
                [
                    'date_value'  => $row['date_value'],
                    'date_label'  => $row['date_label'],
                    'mcftr'       => $row['mcftr'],
                    'mcftr_pct'   => $row['mcftr_pct'],
                    'sc_top10'    => $row['sc_top10'],
                    'sc_top10_pct'=> $row['sc_top10_pct'],
                ],
                ['%s', '%s', '%f', '%f', '%f', '%f']
            );
            $count++;
        }

        wp_redirect($redirect_url . '&tsc_message=success&tsc_count=' . $count);
        exit;
    }

    public function ajax_get_data() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT date_value, date_label, mcftr, mcftr_pct, sc_top10, sc_top10_pct
             FROM {$this->table_name}
             ORDER BY date_value ASC",
            ARRAY_A
        );

        if (empty($results)) {
            wp_send_json_error(['message' => 'Нет данных']);
        }

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'date'        => $row['date_value'],
                'label'       => $row['date_label'],
                'mcftr'       => (float)$row['mcftr'],
                'mcftr_pct'   => $row['mcftr_pct'] !== null ? (float)$row['mcftr_pct'] : null,
                'sc_top10'    => (float)$row['sc_top10'],
                'sc_top10_pct'=> $row['sc_top10_pct'] !== null ? (float)$row['sc_top10_pct'] : null,
            ];
        }

        wp_send_json_success($data);
    }

    public function render_shortcode($atts) {
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js', [], '4.4.7', true);
        wp_enqueue_script(
            'chartjs-plugin-annotation',
            'https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.1.0/dist/chartjs-plugin-annotation.min.js',
            ['chart-js'],
            '3.1.0',
            true
        );
        wp_enqueue_script('tsc-frontend', TSC_PLUGIN_URL . 'assets/js/frontend.js', ['chart-js', 'chartjs-plugin-annotation'], TSC_VERSION, true);
        wp_enqueue_style('tsc-frontend', TSC_PLUGIN_URL . 'assets/css/frontend.css', [], TSC_VERSION);

        wp_localize_script('tsc-frontend', 'tscConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tsc_frontend'),
        ]);

        ob_start();
        ?>
        <div id="tsc-wrapper" class="tsc-wrapper">
            <div class="tsc-chart-container">
                <canvas id="tsc-chart"></canvas>
            </div>
            <div class="tsc-calculator">
                <div class="tsc-calc-row">
                    <div class="tsc-calc-field">
                        <label for="tsc-amount">Сумма инвестирования</label>
                        <input type="text" id="tsc-amount" value="500 000" inputmode="numeric">
                    </div>
                    <div class="tsc-calc-field">
                        <label for="tsc-date-from">Дата подключения</label>
                        <select id="tsc-date-from"></select>
                    </div>
                    <div class="tsc-calc-field">
                        <label for="tsc-date-to">Дата отключения</label>
                        <select id="tsc-date-to"></select>
                    </div>
                    <div class="tsc-calc-field tsc-calc-btn-wrap">
                        <button id="tsc-calculate" type="button">Рассчитать</button>
                    </div>
                </div>
                <div id="tsc-results" class="tsc-results" style="display:none;">
                    <div class="tsc-result-card">
                        <div class="tsc-result-name" id="tsc-result-name">CNY+акции</div>
                        <div class="tsc-result-value" id="tsc-result-money"></div>
                        <div class="tsc-result-pct" id="tsc-result-pct"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

Trading_Strategy_Calculator::get_instance();
