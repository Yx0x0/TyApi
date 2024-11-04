<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

class TyApi_Action extends Typecho_Widget implements Widget_Interface_Do
{
    private $db;
    private $options;
    
    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
        $this->db = Typecho_Db::get();
        $this->options = Helper::options();
        
        // 设置响应头
        $this->response->setContentType('application/json');
        
        try {
            // 直接从数据库获取插件配置
            $pluginConfig = $this->db->fetchRow($this->db->select()->from('table.options')
                ->where('name = ?', 'plugin:TyApi'));
                
            if (!$pluginConfig) {
                throw new Exception('Plugin configuration not found');
            }
            
            $config = unserialize($pluginConfig['value']);
            
            // 验证API密钥
            $apiKey = $this->request->get('api_key');
            if (empty($config['apiKey']) || $apiKey !== $config['apiKey']) {
                $this->outputJson([
                    'code' => 403,
                    'message' => 'Invalid API key'
                ]);
            }
        } catch (Exception $e) {
            $this->outputJson([
                'code' => 500,
                'message' => '插件配置错误：' . $e->getMessage()
            ]);
        }
    }
    
    // 修改获取配置的方法
    private function getPluginConfig()
    {
        $pluginConfig = $this->db->fetchRow($this->db->select()->from('table.options')
            ->where('name = ?', 'plugin:TyApi'));
            
        if (!$pluginConfig) {
            throw new Exception('Plugin configuration not found');
        }
        
        return unserialize($pluginConfig['value']);
    }
    
    // 获取最新文章
    public function recentPosts()
    {
        try {
            $page = max(1, intval($this->request->get('page', 1))); // 确保页码至少为1
            
            $config = $this->getPluginConfig();
            $configPageSize = $config['pageSize'];
            
            // 优先使用URL中的pageSize参数
            $urlPageSize = $this->request->get('pageSize');
            
            // 如果URL中有pageSize参数，使用它；否则使用配置值
            if ($urlPageSize !== null) {
                $pageSize = intval($urlPageSize);
                $useLimit = $pageSize > 0;  // 如果URL中的pageSize为0，则不使用分页
            } else {
                // 检查配置的pageSize，如果为空或0则不使用分页
                $useLimit = !empty($configPageSize) && intval($configPageSize) > 0;
                $pageSize = $useLimit ? intval($configPageSize) : 0;
            }
            
            // 先获取总文章数
            $totalPosts = $this->getTotalPosts();
            $totalPages = $useLimit ? max(1, ceil($totalPosts / $pageSize)) : 1;
            
            // 如果使用分页，检查页码是否有效
            if ($useLimit && $page > $totalPages) {
                $this->outputJson([
                    'code' => 200,
                    'message' => '页码超出范围',
                    'data' => [],
                    'page' => [
                        'current' => intval($page),
                        'pageSize' => intval($pageSize),
                        'total' => $totalPosts,
                        'totalPages' => $totalPages
                    ]
                ]);
                return;
            }
            
            $select = $this->db->select('cid', 'title', 'created', 'modified', 'text', 'authorId')
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('created', Typecho_Db::SORT_DESC);
            
            // 只有在需要分页时才应用分页
            if ($useLimit) {
                $select->page($page, $pageSize);
            }
            
            $posts = $this->db->fetchAll($select);
            
            if (empty($posts)) {
                $this->outputJson([
                    'code' => 200,
                    'message' => '没有找到文章',
                    'data' => [],
                    'page' => [
                        'current' => intval($page),
                        'pageSize' => intval($pageSize),
                        'total' => $totalPosts,
                        'totalPages' => $totalPages
                    ]
                ]);
                return;
            }
            
            $result = [];
            foreach ($posts as $post) {
                $tempContent = new Widget_Abstract_Contents($this->request, $this->response);
                $content = $tempContent->markdown($post['text']);
                $content = preg_replace('/<!--markdown-->/', '', $content);
                $plainText = strip_tags($content);
                
                $excerpt = mb_substr($plainText, 0, 200, 'UTF-8');
                if (mb_strlen($plainText, 'UTF-8') > 200) {
                    $excerpt .= '...';
                }
                
                $result[] = [
                    'id' => $post['cid'],
                    'title' => $post['title'],
                    'content' => [
                        'markdown' => $post['text'],
                        'html' => $content,
                        'text' => $plainText,
                        'excerpt' => $excerpt
                    ],
                    'created' => date('c', $post['created']),
                    'modified' => date('c', $post['modified']),
                    'author' => $this->getAuthor($post['authorId'])
                ];
            }
            
            $this->outputJson([
                'code' => 200,
                'data' => $result,
                'page' => [
                    'current' => intval($page),
                    'pageSize' => intval($pageSize),
                    'total' => $totalPosts,
                    'totalPages' => $totalPages
                ]
            ]);
            
        } catch (Exception $e) {
            $this->outputJson([
                'code' => 500,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    // 获取分类文章
    public function categoryPosts()
    {
        try {
            $categoryId = $this->request->get('mid');
            if (!$categoryId) {
                $this->outputJson([
                    'code' => 400,
                    'message' => 'Category ID is required'
                ]);
            }
            
            $page = max(1, intval($this->request->get('page', 1))); // 确保页码至少为1
            
            $config = $this->getPluginConfig();
            $configPageSize = $config['pageSize'];
            
            // 优先使用URL中的pageSize参数
            $urlPageSize = $this->request->get('pageSize');
            
            // 如果URL中有pageSize参数，使用它；否则使用配置值
            if ($urlPageSize !== null) {
                $pageSize = intval($urlPageSize);
                $useLimit = $pageSize > 0;  // 如果URL中的pageSize为0，则不使用分页
            } else {
                // 检查配置的pageSize，如果为空或0则不使用分页
                $useLimit = !empty($configPageSize) && intval($configPageSize) > 0;
                $pageSize = $useLimit ? intval($configPageSize) : 0;
            }
            
            // 先获取总文章数
            $totalPosts = $this->getTotalPosts($categoryId);
            
            // 计算总页数
            $totalPages = $useLimit ? max(1, ceil($totalPosts / max(1, $pageSize))) : 1;
            
            // 如果页码超出范围，直接返回空数据
            if ($page > $totalPages) {
                $this->outputJson([
                    'code' => 200,
                    'message' => '页码超出范围',
                    'data' => [],
                    'category' => $this->getCategory($categoryId),
                    'page' => [
                        'current' => intval($page),
                        'pageSize' => intval($pageSize),
                        'total' => $totalPosts,
                        'totalPages' => $totalPages
                    ]
                ]);
                return;
            }
            
            $select = $this->db->select('table.contents.cid', 'table.contents.title', 
                                      'table.contents.created', 'table.contents.modified', 
                                      'table.contents.text', 'table.contents.authorId')
                ->from('table.contents')
                ->join('table.relationships', 'table.contents.cid = table.relationships.cid')
                ->where('table.relationships.mid = ?', $categoryId)
                ->where('table.contents.type = ?', 'post')
                ->where('table.contents.status = ?', 'publish')
                ->order('table.contents.created', Typecho_Db::SORT_DESC);
                
            // 只有在需要分页时才应用分页
            if ($useLimit) {
                $select->page($page, $pageSize);
            }
            
            $posts = $this->db->fetchAll($select);
            
            if (empty($posts)) {
                $this->outputJson([
                    'code' => 200,
                    'message' => '没有找到文章',
                    'data' => [],
                    'category' => $this->getCategory($categoryId),
                    'page' => [
                        'current' => intval($page),
                        'pageSize' => intval($pageSize),
                        'total' => $totalPosts,
                        'totalPages' => $totalPages
                    ]
                ]);
                return;
            }
            
            $result = [];
            foreach ($posts as $post) {
                $tempContent = new Widget_Abstract_Contents($this->request, $this->response);
                $content = $tempContent->markdown($post['text']);
                $content = preg_replace('/<!--markdown-->/', '', $content);
                $plainText = strip_tags($content);
                
                $excerpt = mb_substr($plainText, 0, 200, 'UTF-8');
                if (mb_strlen($plainText, 'UTF-8') > 200) {
                    $excerpt .= '...';
                }
                
                $result[] = [
                    'id' => $post['cid'],
                    'title' => $post['title'],
                    'content' => [
                        'markdown' => $post['text'],
                        'html' => $content,
                        'text' => $plainText,
                        'excerpt' => $excerpt
                    ],
                    'created' => date('c', $post['created']),
                    'modified' => date('c', $post['modified']),
                    'author' => $this->getAuthor($post['authorId'])
                ];
            }
            
            $this->outputJson([
                'code' => 200,
                'data' => $result,
                'category' => $this->getCategory($categoryId),
                'page' => [
                    'current' => intval($page),
                    'pageSize' => intval($pageSize),
                    'total' => $totalPosts,
                    'totalPages' => $totalPages
                ]
            ]);
            
        } catch (Exception $e) {
            $this->outputJson([
                'code' => 500,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    // 推送文章
    public function pushPost()
    {
        // 检查是否为POST请求
        if (!$this->request->isPost()) {
            $this->response->throwJson([
                'code' => 405,
                'message' => 'Method not allowed'
            ]);
        }
        
        // 获取POST数据
        $title = $this->request->get('title');
        $content = $this->request->get('content');
        $category = $this->request->get('category');
        
        if (!$title || !$content) {
            $this->response->throwJson([
                'code' => 400,
                'message' => 'Title and content are required'
            ]);
        }
        
        // 创建文章
        $insertData = [
            'title' => $title,
            'text' => $content,
            'created' => time(),
            'modified' => time(),
            'type' => 'post',
            'status' => 'publish',
            'authorId' => 1, // 默认作者ID
        ];
        
        try {
            $insertId = $this->db->query($this->db->insert('table.contents')->rows($insertData));
            
            // 如果有分类，添加分类关系
            if ($category) {
                $this->db->query($this->db->insert('table.relationships')->rows([
                    'cid' => $insertId,
                    'mid' => $category
                ]));
            }
            
            $this->response->throwJson([
                'code' => 200,
                'message' => 'Post created successfully',
                'data' => ['id' => $insertId]
            ]);
        } catch (Exception $e) {
            $this->response->throwJson([
                'code' => 500,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    // 获取作者信息
    private function getAuthor($authorId)
    {
        $author = $this->db->fetchRow($this->db->select()
            ->from('table.users')
            ->where('uid = ?', $authorId));
            
        return [
            'id' => $author['uid'],
            'name' => $author['name']
        ];
    }
    
    // 获取分类信息
    private function getCategory($categoryId)
    {
        $category = $this->db->fetchRow($this->db->select()
            ->from('table.metas')
            ->where('mid = ?', $categoryId)
            ->where('type = ?', 'category'));
            
        return [
            'id' => $category['mid'],
            'name' => $category['name'],
            'slug' => $category['slug']
        ];
    }
    
    // 获取文章总数
    private function getTotalPosts($categoryId = null)
    {
        if ($categoryId) {
            $select = $this->db->select('COUNT(DISTINCT table.contents.cid) AS total')
                ->from('table.contents')
                ->join('table.relationships', 'table.contents.cid = table.relationships.cid')
                ->where('table.contents.type = ?', 'post')
                ->where('table.contents.status = ?', 'publish')
                ->where('table.relationships.mid = ?', $categoryId);
        } else {
            $select = $this->db->select('COUNT(cid) AS total')
                ->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish');
        }
        
        $row = $this->db->fetchRow($select);
        return intval($row['total']);
    }
    
    /**
     * 输出JSON数据
     * 
     * @param array $data
     */
    private function outputJson($data)
    {
        // 确保清除之前的输出
        ob_clean();
        
        // 设置响应头
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // 输出JSON数据
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * 实现接口方法
     */
    public function action(){}
} 