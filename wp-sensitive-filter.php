<?php
/*
Plugin Name: DFA 敏感词卫士
Description: 基于 DFA 算法。支持设置敏感词，评论拦截、文章敏感词提醒、用户名注册屏蔽。
Author: 欧叶
Author URI: https://github.com/O1dYer
*/

if (!defined('ABSPATH')) exit;

/**
 * DFA 核心过滤类
 */
class DFASensitiveFilter {
    private $tree = [];

    public function __construct($words_str) {
        $words = preg_split('/[,\n\r\x{3001}]/u', $words_str);
        foreach ($words as $word) {
            $this->addWord(trim($word));
        }
    }

    private function addWord($word) {
        if (empty($word)) return;
        $temp = &$this->tree;
        $len = mb_strlen($word, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($word, $i, 1, 'UTF-8');
            if (!isset($temp[$char])) $temp[$char] = [];
            $temp = &$temp[$char];
        }
        $temp['is_end'] = true;
    }

    public function hasSensitive($text) {
        if (empty($text)) return false;
        $clean_text = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9]/u', '', $text);
        $len = mb_strlen($clean_text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $temp = $this->tree;
            for ($j = $i; $j < $len; $j++) {
                $char = mb_substr($clean_text, $j, 1, 'UTF-8');
                if (!isset($temp[$char])) break;
                if (isset($temp[$char]['is_end'])) return true;
                $temp = $temp[$char];
            }
        }
        return false;
    }
}

/**
 * 插件功能主逻辑类
 */
class WP_DFA_Shield {
    private static $filter = null;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        
        // 隐藏原生评论过滤设置
        add_action('admin_head-options-discussion.php', [__CLASS__, 'hide_native_discussion_settings']);
        
        add_filter('wp_insert_post_data', [__CLASS__, 'check_post_content'], 10, 2);
        add_action('admin_notices', [__CLASS__, 'show_sensitive_notice']);
        add_filter('pre_comment_approved', [__CLASS__, 'set_comment_status'], 10, 2);
        add_action('comment_post', [__CLASS__, 'check_and_die_comment'], 10, 3);
        add_filter('validate_username', [__CLASS__, 'filter_username'], 10, 2);
        add_filter('registration_errors', [__CLASS__, 'username_error_msg'], 10, 2);
    }

    /**
     * 新增：隐藏原生评论审核和关键字区块
     */
    public static function hide_native_discussion_settings() {
        ?>
        <style>
            /* 隐藏“评论审核”和“禁止使用的评论关键字”对应的表格行或区块 */
            #moderation_keys, #disallowed_keys { display: none; }
            #moderation_keys + p, #disallowed_keys + p { display: none; }
            th:has(+ td #moderation_keys), th:has(+ td #disallowed_keys) { display: none; }
            tr:has(#moderation_keys), tr:has(#disallowed_keys) { display: none; }
        </style>
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                // 兼容性处理：通过查找包含特定文本的 label 或 row 来隐藏
                const rows = document.querySelectorAll('tr');
                rows.forEach(row => {
                    if (row.innerText.includes('评论审核') || row.innerText.includes('禁止使用的评论关键字')) {
                        row.style.display = 'none';
                    }
                });
            });
        </script>
        <?php
    }

    private static function get_filter() {
        if (self::$filter === null) {
            $words = get_option('dfa_sensitive_words', '');
            self::$filter = new DFASensitiveFilter($words);
        }
        return self::$filter;
    }

    public static function check_post_content($data, $postarr) {
        if ($data['post_status'] === 'trash' || $data['post_status'] === 'auto-draft') return $data;
        if (self::get_filter()->hasSensitive($data['post_title'] . $data['post_content'])) {
            $data['post_status'] = 'draft';
            add_filter('redirect_post_location', function($location) {
                return add_query_arg('dfa_warning', '1', $location);
            });
        }
        return $data;
    }

    public static function show_sensitive_notice() {
        if (isset($_GET['dfa_warning']) && $_GET['dfa_warning'] == '1') {
            echo '<div class="notice notice-error is-dismissible">
                    <p><strong>🚨 发现敏感词：</strong>文章已自动保存为<b>草稿</b>，但由于包含敏感词，无法直接发布。请修改后再试。</p>
                  </div>';
        }
    }

    public static function set_comment_status($approved, $commentdata) {
        if (self::get_filter()->hasSensitive($commentdata['comment_content'])) {
            return 'spam';
        }
        return $approved;
    }

    public static function check_and_die_comment($comment_ID, $comment_approved, $commentdata) {
        if ($comment_approved === 'spam' && self::get_filter()->hasSensitive($commentdata['comment_content'])) {
            wp_die('您的评论包含违规词汇，已被系统拦截。', '评论违规', ['response' => 403]);
        }
    }

    public static function add_admin_menu() {
        add_options_page('敏感词设置', '敏感词过滤', 'manage_options', 'dfa-filter-settings', [__CLASS__, 'settings_page']);
    }

    public static function register_settings() {
        register_setting('dfa_settings_group', 'dfa_sensitive_words');
    }

    public static function settings_page() {
        ?>
        <div class="wrap">
            <h1 style="margin-bottom: 20px;">🛡️ 敏感词过滤设置 (DFA 算法)</h1>
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <div class="postbox" style="background: #fff; border: 1px solid #ccd0d4;">
                            <h2 class="hndle" style="padding: 12px; border-bottom: 1px solid #eee; margin: 0;"><span>词库配置</span></h2>
                            <div class="inside" style="padding: 15px;">
                                <form method="post" action="options.php">
                                    <?php settings_fields('dfa_settings_group'); ?>
                                    <p style="margin-top: 0; margin-bottom: 10px;">请输入敏感词，支持使用<strong>换行</strong>、<strong>逗号</strong>或<strong>顿号</strong>分隔。</p>
                                    <textarea name="dfa_sensitive_words" rows="15" style="width:100%; font-family: monospace; padding: 10px; border: 1px solid #ccc; background: #fff; color: #333;"><?php echo esc_textarea(get_option('dfa_sensitive_words')); ?></textarea>
                                    <div style="margin-top: 15px;">
                                        <?php submit_button('保存所有更改', 'primary', 'submit', false); ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox" style="background: #fff; border: 1px solid #ccd0d4;">
                            <h2 class="hndle" style="padding: 12px; border-bottom: 1px solid #eee; margin: 0;"><span>功能说明</span></h2>
                            <div class="inside" style="margin: 6px 0 0; padding: 0 12px 12px;">
                                <ul style="margin: 0; padding-left: 18px; list-style-type: disc;">
                                    <li style="margin: 0 0 8px 0; line-height: 1.4;"><strong>高效匹配：</strong>采用 DFA 算法，毫秒级检索。</li>
                                    <li style="margin: 0 0 8px 0; line-height: 1.4;"><strong>干扰规避：</strong>自动识别并过滤词汇间的星号、空格等干扰符。</li>
                                    <li style="margin: 0 0 8px 0; line-height: 1.4;"><strong>文章检测：</strong>包含敏感词的文章将保存为草稿，无法发布。</li>
                                    <li style="margin: 0 0 8px 0; line-height: 1.4;"><strong>评论拦截：</strong>违规评论被自动拦截，并转入“垃圾评论”。</li>
                                    <li style="margin: 0; line-height: 1.4;"><strong>用户名屏蔽：</strong>禁止注册包含敏感词的用户名。</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .wrap #post-body { color: #2c3338; }
        </style>
        <?php
    }

    public static function filter_username($valid, $username) {
        if (self::get_filter()->hasSensitive($username)) return false;
        return $valid;
    }

    public static function username_error_msg($errors, $sanitized_user_login) {
        if (self::get_filter()->hasSensitive($sanitized_user_login)) {
            $errors->add('prohibited_username', '<strong>错误</strong>：用户名包含违规词汇。');
        }
        return $errors;
    }
}

WP_DFA_Shield::init();