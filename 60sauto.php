<?php
/**
 * 60s读懂世界 Typecho自动发布脚本【美化后台面板｜日志分页｜清空日志｜正确文章链接】
 * 放置网站根目录 60sauto.php
 * 管理面板地址：https://xxx/60sauto.php?admin=1&key=管理员密钥
 * 定时触发地址：https://xxx/60sauto.php?key=任务密钥
 */
if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', __DIR__);
}
require_once __TYPECHO_ROOT_DIR__ . '/config.inc.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Common.php';
require_once __TYPECHO_ROOT_DIR__ . '/var/Typecho/Db.php';

$configFile = __DIR__ . '/60s_config.json';
$logFile = __DIR__ . '/60s_log.json';

// 默认配置
$defaultConfig = [
    'admin_key' => 'admin888',
    'secretKey' => 'task123456',
    'authorUid' => 1,
    'categoryValue' => 'default', // 支持填【分类名称】或者【分类缩略名】自动识别
    'tagsStr' => '60s读世界,每日早报',
    'isPublish' => true,
    'imgPath' => '/usr/uploads/sixtyimg/',
    'jsonPath' => '/usr/uploads/sixtyjson/',
    'apiList' => [
        'https://60s.viki.moe/v2/60s'
    ]
];

// 读取配置
if (file_exists($configFile)) {
    $cfg = json_decode(file_get_contents($configFile), true);
    if (!$cfg) $cfg = $defaultConfig;
} else {
    $cfg = $defaultConfig;
    file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 同时匹配 分类name(名称) 和 slug(缩略名)，自动查询mid
function getCategoryMidByNameOrSlug($categoryValue, $db)
{
    $query = $db->select('mid,name,slug')
        ->from('table.metas')
        ->where('type = ?', 'category')
        ->where('(name = ? OR slug = ?)', $categoryValue, $categoryValue);
    $row = $db->fetchRow($query);
    if ($row) {
        return $row['mid'];
    }
    return false;
}

// 保存日志函数
function saveLog($logFile, $item)
{
    $logs = [];
    if (file_exists($logFile)) {
        $logs = json_decode(file_get_contents($logFile), true) ?: [];
    }
    array_unshift($logs, $item);
    $logs = array_slice($logs, 0, 100); //最多保留100条记录
    file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 拉取API数据（自动遍历备用接口）
function fetch60s($apiList)
{
    foreach ($apiList as $apiUrl) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10; Win64; x64) AppleWebKit/537.36');
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && !empty($resp)) {
            $raw = json_decode($resp, true);
            if ($raw && isset($raw['code']) && $raw['code'] == 200 && isset($raw['data'])) {
                return $raw['data'];
            }
        }
    }
    return false;
}

// 保存图片
function saveImage($imgUrl, $dateStr, $imgDir, $webImgPath)
{
    if (empty($imgUrl)) return '';
    $parse = parse_url($imgUrl);
    $ext = pathinfo($parse['path'], PATHINFO_EXTENSION);
    if (!$ext) $ext = 'jpg';
    $saveName = md5($dateStr . $imgUrl) . ".{$ext}";
    $fullPath = $imgDir . DIRECTORY_SEPARATOR . $saveName;
    $ch = curl_init($imgUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION,true);
    $imgRaw = curl_exec($ch);
    curl_close($ch);
    if ($imgRaw) {
        file_put_contents($fullPath, $imgRaw);
        return $webImgPath . $saveName;
    }
    return $imgUrl;
}

$db = Typecho_Db::get();

// ========== 清空日志处理 ==========
if(isset($_POST['clearLog'])){
    if($_POST['admin_key'] !== $cfg['admin_key']){
        echo '<script>alert("管理员密钥错误");location.href="?admin=1&key='.$_POST['admin_key'].'"</script>';
        exit;
    }
    if(file_exists($logFile)){
        unlink($logFile);
    }
    echo '<script>alert("日志已全部清空");location.href="?admin=1&key='.$cfg['admin_key'].'"</script>';
    exit;
}

// ========== 管理面板提交保存配置 ==========
if (isset($_POST['savecfg'])) {
    if ($_POST['admin_key'] !== $cfg['admin_key']) {
        echo '<script>alert("管理员密钥错误");location.href="?admin=1&key='.$_POST['admin_key'].'"</script>';
        exit;
    }
    $newCfg = $cfg;
    $newCfg['admin_key'] = $_POST['new_admin_key'];
    $newCfg['secretKey'] = $_POST['new_task_key'];
    $newCfg['authorUid'] = intval($_POST['authorUid']);
    $newCfg['categoryValue'] = trim($_POST['categoryValue']);
    $newCfg['tagsStr'] = $_POST['tagsStr'];
    $newCfg['isPublish'] = isset($_POST['isPublish']);
    $apiArr = array_filter(array_map('trim', explode("\n", $_POST['apiText'])));
    $newCfg['apiList'] = $apiArr;
    file_put_contents($configFile, json_encode($newCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo '<script>alert("配置保存成功！支持填写分类名称 或 分类缩略名");location.href="?admin=1&key='.$newCfg['admin_key'].'"</script>';
    exit;
}

// ========== 管理面板展示 ==========
if (isset($_GET['admin']) && $_GET['admin'] == 1) {
    if (!isset($_GET['key']) || $_GET['key'] !== $cfg['admin_key']) {
        die("管理员密钥错误，禁止访问面板");
    }
    $logs = file_exists($logFile) ? json_decode(file_get_contents($logFile),true) : [];
    $apiText = implode("\n", $cfg['apiList']);
    $siteUrl = Typecho_Common::url('/', $options);

    // 日志分页设置
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $pageSize = 10;
    $total = count($logs);
    $totalPage = ceil($total / $pageSize);
    $offset = ($page - 1) * $pageSize;
    $currentLogs = array_slice($logs, $offset, $pageSize);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>60s自动发布管理面板</title>
<style>
*{box-sizing:border-box;font-family:"Microsoft YaHei",system-ui,-apple-system}
body{background-color:#f0f2f5;margin:0;padding:30px 15px;color:#333}
.wrap{max-width:1000px;margin:0 auto}
.card{background:#ffffff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.08);padding:24px;margin-bottom:24px}
.card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.card h2{margin:0;font-size:20px;color:#1f2937;border-left:4px solid #3b82f6;padding-left:12px}
label{display:block;margin:14px 0 6px;font-weight:500;color:#444}
input,textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;transition:border 0.2s}
input:focus,textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,0.1)}
button{padding:10px 20px;background:#3b82f6;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px;transition:background 0.2s}
button:hover{background:#2563eb}
button.danger{background:#ef4444}
button.danger:hover{background:#dc2626}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:14px}
th,td{border:1px solid #e5e7eb;padding:12px;text-align:left;vertical-align:middle}
th{background:#f8fafc;font-weight:500}
tr:nth-child(even){background:#fbfdff}
a{color:#3b82f6;text-decoration:none}
a:hover{text-decoration:underline}
.pagination{margin-top:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.pagination button{background:#fff;color:#333;border:1px solid #d1d5db}
.pagination button:hover{background:#f3f4f6}
.pagination button:disabled{opacity:0.5;cursor:not-allowed}
.success{color:#10b981;font-weight:500}
.fail{color:#ef4444;font-weight:500}
.checkbox-line{display:flex;align-items:center;gap:8px;margin-top:10px}
.checkbox-line input{width:auto}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h2>⚙️ 基础配置</h2>
        <form method="post">
            <input type="hidden" name="admin_key" value="<?php echo htmlspecialchars($cfg['admin_key']) ?>">
            <label>管理员密钥（登录面板）</label>
            <input type="text" name="new_admin_key" value="<?php echo htmlspecialchars($cfg['admin_key']) ?>">
            <label>任务调用密钥（定时任务用）</label>
            <input type="text" name="new_task_key" value="<?php echo htmlspecialchars($cfg['secretKey']) ?>">
            <label>作者UID</label>
            <input type="number" name="authorUid" value="<?php echo $cfg['authorUid'] ?>">
            <label>分类（填写分类名称 / 缩略名，程序自动识别）</label>
            <input type="text" name="categoryValue" value="<?php echo htmlspecialchars($cfg['categoryValue']) ?>">
            <label>文章标签，多个用逗号分隔</label>
            <input type="text" name="tagsStr" value="<?php echo htmlspecialchars($cfg['tagsStr']) ?>">
            <label>API地址（一行一个，主API放最上方）</label>
            <textarea rows="4" name="apiText"><?php echo htmlspecialchars($apiText) ?></textarea>
            <div class="checkbox-line">
                <input type="checkbox" name="isPublish" id="ispub" <?php echo $cfg['isPublish']?'checked':'' ?>>
                <label for="ispub">直接发布文章，取消勾选则保存草稿</label>
            </div>
            <br>
            <button type="submit" name="savecfg">保存配置</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>📜 发布执行日志</h2>
            <form method="post" onsubmit="return confirm('确定要清空全部日志吗？此操作不可恢复！')">
                <input type="hidden" name="admin_key" value="<?php echo htmlspecialchars($cfg['admin_key']) ?>">
                <button class="danger" type="submit" name="clearLog">清空全部日志</button>
            </form>
        </div>
        <?php if(empty($currentLogs)): ?>
            <p>暂无日志记录</p>
        <?php else: ?>
        <table>
            <tr>
                <th>执行时间</th>
                <th>状态</th>
                <th>文章日期</th>
                <th>文章链接</th>
                <th>备注信息</th>
            </tr>
            <?php foreach($currentLogs as $item): ?>
            <tr>
                <td><?php echo $item['time'] ?></td>
                <td><?php echo $item['success'] ? '<span class="success">成功</span>' : '<span class="fail">失败</span>' ?></td>
                <td><?php echo $item['date'] ?: '-' ?></td>
                <td>
                    <?php if($item['cid']):
                        // 使用Typecho原生函数获取文章地址，自动适配伪静态
                        $articleLink = Typecho_Common::url('archives/'.$item['cid'], $options);
                    ?>
                    <a target="_blank" href="<?php echo $articleLink ?>">查看文章</a>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($item['msg']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <div class="pagination">
            <button onclick="location.href='?admin=1&key=<?php echo $cfg['admin_key'] ?>&page=<?php echo $page-1 ?>'" <?php if($page <=1) echo 'disabled' ?>>上一页</button>
            <span>第 <?php echo $page ?> / <?php echo $totalPage ?> 页，共 <?php echo $total ?> 条记录</span>
            <button onclick="location.href='?admin=1&key=<?php echo $cfg['admin_key'] ?>&page=<?php echo $page+1 ?>'" <?php if($page >= $totalPage) echo 'disabled' ?>>下一页</button>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<?php
exit;
}

// ========== 定时任务自动发布逻辑 ==========
header('Content-Type: application/json;charset=utf-8');
$getKey = isset($_GET['key']) ? $_GET['key'] : '';
if ($getKey !== $cfg['secretKey']) {
    echo json_encode(['code' => 403, 'msg' => '任务密钥错误']);
    exit;
}

try {
    $imgDir = __TYPECHO_ROOT_DIR__ . $cfg['imgPath'];
    $jsonDir = __TYPECHO_ROOT_DIR__ . $cfg['jsonPath'];
    if (!is_dir($imgDir)) mkdir($imgDir,0755,true);
    if (!is_dir($jsonDir)) mkdir($jsonDir,0755,true);

    $data = fetch60s($cfg['apiList']);
    if (!$data) throw new Exception("所有API均无法获取数据");
    $dateStr = $data['date'];
    $articleTitle = "60秒读懂世界 - {$dateStr}";

    // 自动匹配分类名称或缩略名
    $categoryMid = getCategoryMidByNameOrSlug($cfg['categoryValue'], $db);
    if(!$categoryMid){
        throw new Exception("分类【{$cfg['categoryValue']}】未找到，请确认分类名称/缩略名是否正确");
    }

    // 判断文章是否存在
    $queryExist = $db->select('cid')
        ->from('table.contents')
        ->where('title = ?', $articleTitle)
        ->where('type = ?', 'post');
    $existPost = $db->fetchRow($queryExist);

    if ($existPost) {
        $logItem = [
            'time' => date('Y-m-d H:i:s'),
            'success' => true,
            'date' => $dateStr,
            'cid' => $existPost['cid'],
            'msg' => '文章已存在，跳过发布'
        ];
        saveLog($logFile, $logItem);
        echo json_encode([
            'code' => 200,
            'msg' => "今日【{$dateStr}】文章已存在，无需重复创建",
            'cid' => $existPost['cid']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 下载图片
    $imgMark = '';
    if (!empty($data['img'])) {
        $localImgUrl = saveImage($data['img'], $dateStr, $imgDir, $cfg['imgPath']);
        $imgMark = "![60s配图]({$localImgUrl})\n\n";
    }

    // Markdown正文组装
    $mdContent = $imgMark . "📅 日期：{$dateStr}\n\n";
    foreach ($data['news'] as $line) {
        $mdContent .= "- {$line}\n";
    }
    if (!empty($data['weiyu'])) {
        $mdContent .= "\n> 💡微语：{$data['weiyu']}\n";
    }

    $status = $cfg['isPublish'] ? 'publish' : 'draft';
    $createTime = time();
    // 插入文章
    $insertQuery = $db->insert('table.contents')->rows([
        'title' => $articleTitle,
        'slug' => '60s-' . str_replace('-', '', $dateStr),
        'type' => 'post',
        'status' => $status,
        'created' => $createTime,
        'modified' => $createTime,
        'text' => $mdContent,
        'authorId' => $cfg['authorUid']
    ]);
    $db->query($insertQuery);

    // 插入完成后，重新查询cid
    $getCidQuery = $db->select('cid')
        ->from('table.contents')
        ->where('title = ?', $articleTitle)
        ->where('authorId = ?', $cfg['authorUid'])
        ->where('created = ?', $createTime);
    $articleRow = $db->fetchRow($getCidQuery);
    if(empty($articleRow['cid'])){
        throw new Exception("文章写入成功，但读取CID失败");
    }
    $newCid = $articleRow['cid'];

    // 检查分类关联是否已存在
    $catRelChk = $db->select('cid')->from('table.relationships')
        ->where('cid = ? AND mid = ?', $newCid, $categoryMid);
    $catRelExist = $db->fetchRow($catRelChk);
    if(!$catRelExist){
        // 插入文章-分类关联
        $relCatQuery = $db->insert('table.relationships')->rows([
            'cid' => $newCid,
            'mid' => $categoryMid
        ]);
        $db->query($relCatQuery);
        // 更新分类计数 count+1【低版本Typecho兼容写法】
        $db->query("UPDATE ".$db->getPrefix()."metas SET count = count + 1 WHERE mid = ? AND type = ?",array($categoryMid,'category'));
    }

    // 添加标签
    $tagArr = array_map('trim', explode(',', $cfg['tagsStr']));
    $tagArr = array_filter($tagArr);
    if (!empty($tagArr)) {
        foreach ($tagArr as $tagName) {
            $tagQuery = $db->select('mid,count')->from('table.metas')
                ->where('name = ? AND type = ?', $tagName, 'tag');
            $tagRow = $db->fetchRow($tagQuery);
            if ($tagRow) {
                $mid = $tagRow['mid'];
            } else {
                $mid = $db->insert('table.metas')->rows([
                    'name' => $tagName,
                    'slug' => Typecho_Common::slugName($tagName),
                    'type' => 'tag',
                    'description' => '',
                    'count' => 0
                ])->query();
            }
            // 判断标签关联是否存在
            $relQuery = $db->select('cid')->from('table.relationships')
                ->where('cid = ? AND mid = ?', $newCid, $mid);
            $relExist = $db->fetchRow($relQuery);
            if (!$relExist) {
                $db->insert('table.relationships')->rows([
                    'cid' => $newCid,
                    'mid' => $mid
                ])->query();
                //标签计数+1【低版本Typecho兼容写法】
                $db->query("UPDATE ".$db->getPrefix()."metas SET count = count + 1 WHERE mid = ? AND type = ?",array($mid,'tag'));
            }
        }
    }

    $logItem = [
        'time' => date('Y-m-d H:i:s'),
        'success' => true,
        'date' => $dateStr,
        'cid' => $newCid,
        'msg' => '文章发布成功，已绑定分类、标签并更新计数'
    ];
    saveLog($logFile, $logItem);

    echo json_encode([
        'code' => 201,
        'msg' => "文章创建成功，已绑定分类，后台编辑页可看到选中分类",
        'cid' => $newCid,
        'date' => $dateStr
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $logItem = [
        'time' => date('Y-m-d H:i:s'),
        'success' => false,
        'date' => '',
        'cid' => '',
        'msg' => $e->getMessage()
    ];
    saveLog($logFile, $logItem);
    echo json_encode([
        'code' => 500,
        'msg' => '执行失败',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
