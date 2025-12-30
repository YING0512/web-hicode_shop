<?php
// 引入資料庫連線設定
require 'db.php';

// 設定回應內容為 JSON 格式
header('Content-Type: application/json');

// 取得 HTTP 請求方法
$method = $_SERVER['REQUEST_METHOD'];
// 讀取 JSON input
$data = json_decode(file_get_contents('php://input'), true);

// ----------------------------------------------------------------
// 1. 處理下訂單 (POST)
// ----------------------------------------------------------------
if ($method === 'POST') {
    // 結帳資訊 (使用者 ID 與運送地址)
    $user_id = $data['user_id'];
    $shipping_address = $data['shipping_address'];
    
    // (1) 取得購物車資料
    $stmt = $pdo->prepare("SELECT cart_id FROM Cart WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $cart = $stmt->fetch();

    if (!$cart) {
        http_response_code(400); // 400 Bad Request
        echo json_encode(['error' => 'No cart found']);
        exit();
    }

    $cart_id = $cart['cart_id'];
    // 取得購物車內的所有商品明細
    $stmt = $pdo->prepare("SELECT ci.*, p.price, p.stock_quantity FROM CartItem ci JOIN Product p ON ci.product_id = p.product_id WHERE ci.cart_id = ?");
    $stmt->execute([$cart_id]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        http_response_code(400);
        echo json_encode(['error' => 'Cart is empty']);
        exit();
    }

    // 計算總金額
    $total_amount = 0;
    foreach ($items as $item) {
        $total_amount += $item['price'] * $item['quantity'];
    }

    // --- 開始資料庫交易 (Transaction) ---
    // 確保餘額扣除、庫存扣除、訂單建立的一致性
    try {
        $pdo->beginTransaction();

        // (2) 檢查使用者餘額是否足夠
        // 使用 FOR UPDATE 鎖定該行，防止併發修改用
        $stmtBalance = $pdo->prepare("SELECT wallet_balance FROM User WHERE user_id = ? FOR UPDATE");
        $stmtBalance->execute([$user_id]);
        $user = $stmtBalance->fetch();

        if (!$user) {
             throw new Exception("User not found");
        }
        if ($user['wallet_balance'] < $total_amount) {
             throw new Exception("餘額不足 (需要: " . $total_amount . ", 擁有: " . $user['wallet_balance'] . ")");
        }

        // (3) 扣除買家餘額
        $stmtDeduct = $pdo->prepare("UPDATE User SET wallet_balance = wallet_balance - ? WHERE user_id = ?");
        $stmtDeduct->execute([$total_amount, $user_id]);

        // (4) 建立主要訂單記錄 (Order)
        $stmt = $pdo->prepare("INSERT INTO `Order` (user_id, total_amount, shipping_address, status) VALUES (?, ?, ?, 'PENDING')");
        $stmt->execute([$user_id, $total_amount, $shipping_address]);
        $order_id = $pdo->lastInsertId();

        // (5) 處理個別商品項目：加入明細、扣庫存、轉帳給賣家
        // 用來追蹤有幾個不同的賣家，以便後續建立聊天室
        $distinct_seller_ids = [];

        foreach ($items as $item) {
            // 找出該商品的賣家
            $stmtSeller = $pdo->prepare("SELECT seller_id FROM Product WHERE product_id = ?");
            $stmtSeller->execute([$item['product_id']]);
            $seller = $stmtSeller->fetch();
            if ($seller) {
                $distinct_seller_ids[$seller['seller_id']] = true;
            }

            if (!$seller) {
                throw new Exception("Seller not found for Product ID " . $item['product_id']);
            }

            // 再次檢查庫存是否足夠
            if ($item['stock_quantity'] < $item['quantity']) {
                throw new Exception("Product ID " . $item['product_id'] . " out of stock");
            }

            // 新增訂單明細 (OrderItem)，記錄當下價格
            $stmt = $pdo->prepare("INSERT INTO OrderItem (order_id, product_id, quantity, price_snapshot) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, $item['product_id'], $item['quantity'], $item['price']]);

            // 扣除商品庫存並增加銷售量
            // 使用 AND stock_quantity >= ? 確保這段時間內庫存沒有被其他人搶光 (併發安全)
            $stmt = $pdo->prepare("UPDATE Product SET stock_quantity = stock_quantity - ?, sales_count = sales_count + ? WHERE product_id = ? AND stock_quantity >= ?");
            $stmt->execute([$item['quantity'], $item['quantity'], $item['product_id'], $item['quantity']]);
            
            if ($stmt->rowCount() == 0) {
                // 如果更新影響行數為 0，代表庫存不足
                throw new Exception("Product ID " . $item['product_id'] . " stock insufficient during update");
            }

            // 將款項轉給賣家 (增加賣家餘額)
            $itemTotal = $item['price'] * $item['quantity'];
            $stmtTransfer = $pdo->prepare("UPDATE User SET wallet_balance = wallet_balance + ? WHERE user_id = ?");
            $stmtTransfer->execute([$itemTotal, $seller['seller_id']]);

            // 檢查更新後的庫存，如果變成 <= 0，自動下架商品
            $stmtCheck = $pdo->prepare("SELECT stock_quantity FROM Product WHERE product_id = ?");
            $stmtCheck->execute([$item['product_id']]);
            $prod = $stmtCheck->fetch();
            if ($prod && $prod['stock_quantity'] <= 0) {
                $stmtUpdateStatus = $pdo->prepare("UPDATE Product SET status = 'off_shelf' WHERE product_id = ?");
                $stmtUpdateStatus->execute([$item['product_id']]);
            }
        }

        // (6) 建立買賣雙方的聊天室並發送系統訊息
        foreach (array_keys($distinct_seller_ids) as $s_id) {
             // 建立聊天室 (若已存在則忽略 - INSERT IGNORE)
             $stmtChat = $pdo->prepare("INSERT IGNORE INTO ChatRoom (order_id, buyer_id, seller_id) VALUES (?, ?, ?)");
             $stmtChat->execute([$order_id, $user_id, $s_id]);
             
             // 取得聊天室 ID
             $stmtGetChat = $pdo->prepare("SELECT chat_room_id FROM ChatRoom WHERE order_id = ? AND seller_id = ?");
             $stmtGetChat->execute([$order_id, $s_id]);
             $chatRoom = $stmtGetChat->fetch();
             
             if ($chatRoom) {
                 $roomId = $chatRoom['chat_room_id'];
                 // 發送訂單建立的系統訊息
                 $msg = "訂單 #$order_id 已建立。等待賣家確認。";
                 $stmtMsg = $pdo->prepare("INSERT INTO ChatMessage (chat_room_id, message_type, content) VALUES (?, 'SYSTEM', ?)");
                 $stmtMsg->execute([$roomId, $msg]);
             }
        }

        // (7) 清空購物車
        $stmt = $pdo->prepare("DELETE FROM CartItem WHERE cart_id = ?");
        $stmt->execute([$cart_id]);
        
        // 提交交易：只有到這裡都沒出錯，資料庫才會真正變更
        $pdo->commit();
        echo json_encode(['message' => 'Order placed successfully', 'order_id' => $order_id]);

    } catch (Exception $e) {
        // 若發生錯誤，回滾所有變更 (Rollback)，確保資料一致性
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Order failed: ' . $e->getMessage()]);
    }
    // --- 交易結束 ---

// ----------------------------------------------------------------
// 2. 處理訂單更新 (PUT) - 取消或完成訂單
// ----------------------------------------------------------------
} elseif ($method === 'PUT') {
    // 取消或更新訂單狀態
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = $data['order_id'];
    $user_id = $data['user_id'];
    $action = $data['action'] ?? '';

    // [情境 A] 取消訂單
    if ($action === 'cancel') {
        $reason = $data['reason'] ?? 'User cancelled';

        // (1) 檢查訂單狀態
        $stmt = $pdo->prepare("SELECT status FROM `Order` WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order || $order['status'] !== 'PENDING') {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot cancel order (invalid status)']);
            exit();
        }

        // (2) 開始交易 (處理退貨/退款/庫存回補)
        $pdo->beginTransaction();
        try {
            // 更新狀態為 CANCELLED
            $stmt = $pdo->prepare("UPDATE `Order` SET status = 'CANCELLED', cancellation_reason = ? WHERE order_id = ?");
            $stmt->execute([$reason, $order_id]);

            // 回補庫存
            $stmt = $pdo->prepare("SELECT product_id, quantity FROM OrderItem WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll();

            foreach ($items as $item) {
                // 庫存加回，銷售量扣除
                $stmt = $pdo->prepare("UPDATE Product SET stock_quantity = stock_quantity + ?, sales_count = sales_count - ? WHERE product_id = ?");
                $stmt->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
                
                // 檢查是否需要重新上架 (如果之前從 0 變有貨，但這裡邏輯是庫存<=0才下架，所以如果變有貨，是否自動上架？)
                // 這裡目前只做下架檢查 (雖然庫存增加通常不需要下架，但保留原邏輯結構)
                // 邏輯上：如果庫存 > 0 且狀態是 off_shelf，也許應該改回 on_shelf？
                // 為了安全起見，這邊暫時只做標準下架檢查
            }

            // 在聊天室發送通知
            $stmtChats = $pdo->prepare("SELECT chat_room_id FROM ChatRoom WHERE order_id = ?");
            $stmtChats->execute([$order_id]);
            $rooms = $stmtChats->fetchAll();
            foreach($rooms as $room) {
                $stmtMsg = $pdo->prepare("INSERT INTO ChatMessage (chat_room_id, message_type, content) VALUES (?, 'SYSTEM', ?)");
                $stmtMsg->execute([$room['chat_room_id'], "訂單 #$order_id 已取消。原因: $reason"]);
            }

            // 這裡應該也要有金流退款邏輯 (將錢從賣家扣回給買家)，但範例程式中尚未實作退款
            // 在完整系統中，需要從賣家錢包扣除並加回給買家，或是一開始就存在託管帳戶中

            $pdo->commit();
            echo json_encode(['message' => 'Order cancelled']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to cancel order: ' . $e->getMessage()]);
        }
    
    // [情境 B] 完成訂單 (賣家操作)
    } elseif ($action === 'complete') {
         // 在真實應用中需驗證權限 (是否為該訂單的賣家)
        $stmt = $pdo->prepare("UPDATE `Order` SET status = 'COMPLETED' WHERE order_id = ?");
        $stmt->execute([$order_id]);

        // 發送通知
        $stmtChats = $pdo->prepare("SELECT chat_room_id FROM ChatRoom WHERE order_id = ?");
        $stmtChats->execute([$order_id]);
        $rooms = $stmtChats->fetchAll();
        foreach($rooms as $room) {
            $stmtMsg = $pdo->prepare("INSERT INTO ChatMessage (chat_room_id, message_type, content) VALUES (?, 'SYSTEM', ?)");
            $stmtMsg->execute([$room['chat_room_id'], "訂單 #$order_id 已完成。"]);
        }

        echo json_encode(['message' => 'Order marked as completed']);
    }

// ----------------------------------------------------------------
// 3. 查詢訂單列表 (GET)
// ----------------------------------------------------------------
} elseif ($method === 'GET') {
    $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
    $seller_id = isset($_GET['seller_id']) ? $_GET['seller_id'] : null;

    $ordersMap = [];

    if ($user_id) {
        // [情境 A] 買家查詢自己的訂單
        // 抓取訂單、明細、商品名稱與圖片
        $sql = "SELECT o.*, oi.quantity, oi.price_snapshot, p.name as product_name, p.image_url 
                FROM `Order` o 
                LEFT JOIN OrderItem oi ON o.order_id = oi.order_id 
                LEFT JOIN Product p ON oi.product_id = p.product_id 
                WHERE o.user_id = ? 
                ORDER BY o.order_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();

    } elseif ($seller_id) {
        // [情境 B] 賣家查詢相關訂單 (包含自己商品的訂單)
        // 這裡僅篩選出包含該賣家商品的訂單項目
        $sql = "SELECT o.*, oi.quantity, oi.price_snapshot, p.name as product_name, p.image_url 
                FROM `Order` o 
                JOIN OrderItem oi ON o.order_id = oi.order_id 
                JOIN Product p ON oi.product_id = p.product_id 
                WHERE p.seller_id = ? 
                ORDER BY o.order_date DESC";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$seller_id]);
        $rows = $stmt->fetchAll();
    } else {
        echo json_encode([]);
        exit;
    }

    // 將資料庫回傳的扁平資料 (Flat Rows) 組合成巢狀結構 (Nested Structure)
    // 因為一筆訂單可能對應多筆商品 (Join 之後會有多行)
    foreach ($rows as $row) {
        $order_id = $row['order_id'];
        if (!isset($ordersMap[$order_id])) {
            $ordersMap[$order_id] = [
                'order_id' => $row['order_id'],
                'user_id' => $row['user_id'],
                'total_amount' => $row['total_amount'],
                'status' => $row['status'],
                'shipping_address' => $row['shipping_address'],
                'order_date' => $row['order_date'],
                'cancellation_reason' => $row['cancellation_reason'],
                'items' => []
            ];
        }
        
        if ($row['product_name']) { // 若有商品明細
            $ordersMap[$order_id]['items'][] = [
                'name' => $row['product_name'],
                'price' => $row['price_snapshot'], // 購買當時的價格
                'quantity' => $row['quantity'],
                'image_url' => $row['image_url']
            ];
        }
    }

    // 回傳陣列格式 (重置索引)
    echo json_encode(array_values($ordersMap));
}
?>
