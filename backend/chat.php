<?php
// 引入資料庫連線設定
require 'db.php';

// 設定回應內容為 JSON 格式
header('Content-Type: application/json');

// 取得 HTTP 請求方法
$method = $_SERVER['REQUEST_METHOD'];
// 取得操作動作 (action)
$action = isset($_GET['action']) ? $_GET['action'] : '';

// ----------------------------------------------------------------
// 1. 處理讀取請求 (GET)
// ----------------------------------------------------------------
if ($method === 'GET') {
    // [功能 A] 列出聊天列表
    if ($action === 'list_rooms') {
        // 取得使用者 ID (買家或賣家)
        $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
        if (!$user_id) {
            http_response_code(400); echo json_encode(['error' => 'Missing user_id']); exit;
        }

        // 查詢該使用者的所有聊天室
        // 必須抓取對方的名稱 (若我是買家，抓賣家名；若我是賣家，抓買家名)
        // 並同時抓取最後一則訊息與未讀數
        $sql = "SELECT cr.*, 
                       b.username as buyer_name, 
                       s.username as seller_name,
                       (SELECT content FROM ChatMessage cm WHERE cm.chat_room_id = cr.chat_room_id ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                       (SELECT created_at FROM ChatMessage cm WHERE cm.chat_room_id = cr.chat_room_id ORDER BY cm.created_at DESC LIMIT 1) as last_message_time,
                       (SELECT COUNT(*) FROM ChatMessage cm WHERE cm.chat_room_id = cr.chat_room_id AND cm.is_read = 0 AND (cm.sender_id != ? OR cm.sender_id IS NULL)) as unread_count
                FROM ChatRoom cr
                JOIN User b ON cr.buyer_id = b.user_id
                JOIN User s ON cr.seller_id = s.user_id
                WHERE cr.buyer_id = ? OR cr.seller_id = ?
                ORDER BY last_message_time DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $user_id, $user_id]);
        echo json_encode($stmt->fetchAll());

    // [功能 B] 取得特定聊天室的對話記錄
    } elseif ($action === 'get_messages') {
        $room_id = isset($_GET['room_id']) ? $_GET['room_id'] : null;
        if (!$room_id) {
            http_response_code(400); echo json_encode(['error' => 'Missing room_id']); exit;
        }

        // 抓取聊天訊息，並關聯發送者名稱
        $sql = "SELECT cm.*, u.username as sender_name 
                FROM ChatMessage cm 
                LEFT JOIN User u ON cm.sender_id = u.user_id 
                WHERE cm.chat_room_id = ? 
                ORDER BY cm.created_at ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$room_id]);
        echo json_encode($stmt->fetchAll());
    }

// ----------------------------------------------------------------
// 2. 處理寫入請求 (POST)
// ----------------------------------------------------------------
} elseif ($method === 'POST') {
    // 讀取 JSON input
    $data = json_decode(file_get_contents('php://input'), true);
    // POST 可能的 action: send (預設) 或 mark_read (標記已讀)
    $action = $_GET['action'] ?? ($data['action'] ?? 'send');

    // [功能 C] 標記已讀
    if ($action === 'mark_read') {
        $room_id = $data['room_id'] ?? null;
        $user_id = $data['user_id'] ?? null; // 當前正在閱讀訊息的使用者

        if (!$room_id || !$user_id) {
             http_response_code(400); echo json_encode(['error' => 'Missing data']); exit;
        }
        
        // 將該聊天室中「非我發送」且「未讀」的訊息標記為已讀 (is_read = 1)
        // 系統訊息 (sender_id 為 NULL) 也一併標記
        $stmt = $pdo->prepare("UPDATE ChatMessage SET is_read = 1 WHERE chat_room_id = ? AND (sender_id != ? OR sender_id IS NULL) AND is_read = 0");
        $stmt->execute([$room_id, $user_id]);
        
        echo json_encode(['status' => 'marked_read']);
        exit;
    }

    // [功能 D] 發送訊息 (預設)
    $room_id = $data['room_id'];
    $sender_id = $data['sender_id'];
    $content = $data['content'];
    $type = isset($data['type']) ? $data['type'] : 'TEXT'; // 訊息類型 (TEXT, IMAGE, SYSTEM)

    if (!$room_id || !$content) {
         http_response_code(400); echo json_encode(['error' => 'Missing data']); exit;
    }

    try {
        // 寫入訊息
        $stmt = $pdo->prepare("INSERT INTO ChatMessage (chat_room_id, sender_id, message_type, content, is_read) VALUES (?, ?, ?, ?, 0)");
        $stmt->execute([$room_id, $sender_id, $type, $content]);
        echo json_encode(['status' => 'success', 'message_id' => $pdo->lastInsertId()]);
    } catch (Exception $e) {
        http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
