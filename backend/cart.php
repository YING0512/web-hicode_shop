<?php
// 引入資料庫連線設定
require 'db.php';

// 設定回應內容為 JSON 格式
header('Content-Type: application/json');

// 取得 HTTP 請求方法
$method = $_SERVER['REQUEST_METHOD'];
// 讀取 JSON input
$data = json_decode(file_get_contents('php://input'), true);

// 在此範例專案中，我們直接從 URL 或 request header 取得 user_id，以簡化開發
// 在正式環境中，這些資訊應該從後端驗證過的 JWT token 中解析出來
$headers = getallheaders();
// 優先從 GET 參數讀取 user_id (通常是測試用) 或 從 POST body 讀取
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : (isset($data['user_id']) ? intval($data['user_id']) : null);

if (!$user_id) {
    // 除了 GET 以外的操作通常都需要 user_id
    if ($method !== 'OPTIONS' && $method !== 'GET') {
         // 這裡可以做更嚴格的限制
    }
}

// ----------------------------------------------------------------
// 1. 取得購物車內容 (GET)
// ----------------------------------------------------------------
if ($method === 'GET') {
    if (!$user_id) { echo json_encode([]); exit(); }

    // 查找該用戶的購物車 ID
    $stmt = $pdo->prepare("SELECT c.cart_id FROM Cart c WHERE c.user_id = ?");
    $stmt->execute([$user_id]);
    $cart = $stmt->fetch();

    if ($cart) {
        // 如果有購物車，找出裡面的所有商品 (CartItem)
        // 並關聯 Product 資料表以取得商品名稱、價格、庫存
        $stmt = $pdo->prepare("
            SELECT ci.*, p.name, p.price, p.stock_quantity 
            FROM CartItem ci 
            JOIN Product p ON ci.product_id = p.product_id 
            WHERE ci.cart_id = ?
        ");
        $stmt->execute([$cart['cart_id']]);
        echo json_encode(['cart_id' => $cart['cart_id'], 'items' => $stmt->fetchAll()]);
    } else {
        // 如果還沒有購物車，回傳空陣列
        echo json_encode(['message' => 'Cart empty', 'items' => []]);
    }

// ----------------------------------------------------------------
// 2. 加入商品到購物車 (POST)
// ----------------------------------------------------------------
} elseif ($method === 'POST') {
    if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'User ID required']); exit(); }
    
    $product_id = $data['product_id'];
    $quantity = isset($data['quantity']) ? $data['quantity'] : 1;

    // (1) 取得或建立購物車
    $stmt = $pdo->prepare("SELECT cart_id FROM Cart WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $cart = $stmt->fetch();
    
    if (!$cart) {
        $stmt = $pdo->prepare("INSERT INTO Cart (user_id) VALUES (?)");
        $stmt->execute([$user_id]);
        $cart_id = $pdo->lastInsertId();
    } else {
        $cart_id = $cart['cart_id'];
    }

    // (2) 加入或更新商品數量
    // 先檢查該商品是否已經在購物車內
    $stmt = $pdo->prepare("SELECT cart_item_id, quantity FROM CartItem WHERE cart_id = ? AND product_id = ?");
    $stmt->execute([$cart_id, $product_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        // 若已存在，則增加數量
        $new_qty = $existing['quantity'] + $quantity;
        $stmt = $pdo->prepare("UPDATE CartItem SET quantity = ? WHERE cart_item_id = ?");
        $stmt->execute([$new_qty, $existing['cart_item_id']]);
    } else {
        // 若不存在，則新增一筆
        $stmt = $pdo->prepare("INSERT INTO CartItem (cart_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt->execute([$cart_id, $product_id, $quantity]);
    }

    echo json_encode(['message' => 'Added to cart', 'cart_id' => $cart_id]);

// ----------------------------------------------------------------
// 3. 移除購物車項目 (DELETE)
// ----------------------------------------------------------------
} elseif ($method === 'DELETE') {
    if (!$user_id) { http_response_code(401); echo json_encode(['error' => 'User ID required']); exit(); }

    $cart_item_id = isset($_GET['cart_item_id']) ? $_GET['cart_item_id'] : (isset($data['cart_item_id']) ? $data['cart_item_id'] : null);
    
    if (!$cart_item_id) {
        http_response_code(400); echo json_encode(['error' => 'Cart Item ID required']); exit();
    }

    // 驗證並刪除：確保只刪除屬於該使用者購物車的項目
    // 子查詢 (SELECT cart_id FROM Cart WHERE user_id = ?) 確保了安全性
    $stmt = $pdo->prepare("DELETE FROM CartItem WHERE cart_item_id = ? AND cart_id IN (SELECT cart_id FROM Cart WHERE user_id = ?)");
    $stmt->execute([$cart_item_id, $user_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['message' => 'Item removed']);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Item not found or not authorized']);
    }
}
?>
