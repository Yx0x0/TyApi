<?php
/**
 * 为Typecho博客提供REST API接口，支持文章获取和推送
 * 
 * @package TyApi
 * @author 布衣
 * @version 1.0.0
 * @dependence 1.0.0
 * @link https://buyi.info
 * @description 提供文章列表、分类文章、文章推送等API接口，支持API密钥验证和自定义分页
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class TyApi_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     */
    public static function activate()
    {
        Helper::addAction('api', 'TyApi_Action');
        Helper::addRoute('api_recent', '/api/posts/recent', 'TyApi_Action', 'recentPosts');
        Helper::addRoute('api_category', '/api/posts/category', 'TyApi_Action', 'categoryPosts');
        Helper::addRoute('api_push', '/api/posts/push', 'TyApi_Action', 'pushPost');
        return _t('插件已经激活，请设置API密钥');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     */
    public static function deactivate()
    {
        Helper::removeAction('api');
        Helper::removeRoute('api_recent');
        Helper::removeRoute('api_category');
        Helper::removeRoute('api_push');
    }
    
    /**
     * 获取插件配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey', 
            null, 
            '', 
            _t('API访问密钥'), 
            _t('用于验证API请求的密钥，请设置复杂的字符串')
        );
        $form->addInput($apiKey);
        
        $pageSize = new Typecho_Widget_Helper_Form_Element_Text(
            'pageSize', 
            null, 
            '10', 
            _t('每页文章数'), 
            _t('默认每页显示的文章数量')
        );
        $form->addInput($pageSize);
        
        // 检查插件是否已启用并且有配置值
        $db = Typecho_Db::get();
        $pluginConfig = $db->fetchRow($db->select()->from('table.options')
            ->where('name = ?', 'plugin:TyApi'));
        
        $apiKeyDisplay = '你的密钥';
        if ($pluginConfig) {
            $config = unserialize($pluginConfig['value']);
            if (!empty($config['apiKey'])) {
                $apiKeyDisplay = $config['apiKey'];
            }
        }
        
        // 使用HTML格式化使用说明
        $usageHtml = '<div style="background-color: #f8f9fa; padding: 15px; border-radius: 5px;">
            <h3 style="margin-top: 0;">API接口使用说明</h3>
            <div style="margin-top: 15px;">
                <h4 style="margin-top: 10px;">1. 获取最新文章</h4>
                <pre style="background-color: #fff; padding: 10px; border-radius: 3px; word-wrap: break-word; white-space: pre-wrap;">' . 
                Helper::options()->siteUrl . 'api/posts/recent?api_key=' . $apiKeyDisplay . '&page=1&pageSize=10</pre>
                
                <h4 style="margin-top: 15px;">2. 获取分类文章</h4>
                <pre style="background-color: #fff; padding: 10px; border-radius: 3px; word-wrap: break-word; white-space: pre-wrap;">' . 
                Helper::options()->siteUrl . 'api/posts/category?api_key=' . $apiKeyDisplay . '&mid=分类ID&page=1&pageSize=10</pre>
                
                <h4 style="margin-top: 15px;">3. 推送文章（POST请求）</h4>
                <pre style="background-color: #fff; padding: 10px; border-radius: 3px; word-wrap: break-word; white-space: pre-wrap;">' . 
                Helper::options()->siteUrl . 'api/posts/push</pre>
                <p>参数：</p>
                <ul style="margin-bottom: 15px;">
                    <li>api_key: 你的密钥</li>
                    <li>title: 文章标题</li>
                    <li>content: 文章内容</li>
                    <li>category: 分类ID（可选）</li>
                </ul>
                
                <h4 style="margin-top: 15px;">返回数据格式示例：</h4>
                <pre style="background-color: #fff; padding: 10px; border-radius: 3px; word-wrap: break-word; white-space: pre-wrap;">
{
    "code": 200,
    "data": [{
        "id": "文章ID",
        "title": "文章标题",
        "content": {
            "markdown": "Markdown原文",
            "html": "HTML内容",
            "text": "纯文本",
            "excerpt": "文章摘要"
        },
        "created": "创建时间",
        "modified": "修改时间",
        "author": {
            "id": "作者ID",
            "name": "作者名"
        }
    }],
    "page": {
        "current": 当前页码,
        "pageSize": 每页条数,
        "total": 总文章数,
        "totalPages": 总页数
    }
}</pre>
                
                <h4 style="margin-top: 15px;">注意事项：</h4>
                <ol style="margin-bottom: 0;">
                    <li>请妥善保管API密钥</li>
                    <li>建议使用HTTPS保护API调用</li>
                    <li>推送文章接口需要严格控制权限</li>
                    <li>如果设置每页文章数为0或留空，则返回所有文章</li>
                    <li>URL中的pageSize参数优先于插件设置</li>
                </ol>
            </div>
        </div>';
        
        $usage = new Typecho_Widget_Helper_Form_Element_Textarea(
            'usage',
            null,
            null,
            _t('API使用说明'),
            $usageHtml
        );
        $usage->input->setAttribute('style', 'display: none;');
        $form->addInput($usage);
    }
    
    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
    
    /**
     * 插件实现方法
     */
    public static function render(){}
} 