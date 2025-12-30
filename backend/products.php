<?php
// 引入資料庫連線設定
require 'db.php';

// 設定回應內容為 JSON 格式 (讓前端知道回傳的是 JSON 資料)
header('Content-Type: application/json');

// 取得 HTTP 請求方法 (GET, POST, PUT, DELETE)
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------
// 1. 處理讀取商品 (GET)
// ---------------------------------------------------------
if ($method === 'GET') {
    // 接收參數
    // GET 參數若未設定則為 null
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $seller_id = isset($_GET['seller_id']) ? intval($_GET['seller_id']) : null;
    $category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
    $search = isset($_GET['search']) ? $_GET['search'] : null;

    if ($id) {
        // [場景 1] 取得單一商品詳情
        // 抓取商品資訊並關聯賣家名稱 (User table)，且只抓取未被刪除的 (is_deleted = 0)
        $stmt = $pdo->prepare("SELECT p.*, u.username as seller_name FROM Product p JOIN User u ON p.seller_id = u.user_id WHERE p.product_id = ? AND p.is_deleted = 0");
        $stmt->execute([$id]);
        echo json_encode($stmt->fetch());

    } elseif ($seller_id) {
        // [場景 2] 賣家後台：顯示該賣家的「所有」商品
        // 不論是否上架 (on_shelf/off_shelf) 都要顯示，但不包含已刪除 (is_deleted=1) 的商品
        $sql = "SELECT * FROM Product WHERE seller_id = ? AND is_deleted = 0";
        $params = [$seller_id];

        // 賣家後台的篩選條件：分類
        if ($category_id) {
            $sql .= " AND category_id = ?";
            $params[] = $category_id;
        }
        // 賣家後台的篩選條件：搜尋關鍵字 (商品名稱或描述)
        if ($search) {
            $sql .= " AND (name LIKE ? OR description LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        // 新的商品排在前面 (降冪排序)
        $sql .= " ORDER BY product_id DESC"; 

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());

    } else {
        // [場景 3] 公開前台：只顯示「上架中」的商品
        // 必須同時符合 is_deleted=0 (未刪除) 和 status='on_shelf' (上架中)
        $sql = "SELECT p.*, u.username as seller_name FROM Product p JOIN User u ON p.seller_id = u.user_id WHERE p.is_deleted = 0 AND p.status = 'on_shelf'";
        $params = [];

        // 前台篩選：分類
        if ($category_id) {
            $sql .= " AND p.category_id = ?";
            $params[] = $category_id;
        }
        // 前台篩選：搜尋關鍵字 (商品名稱或描述)
        if ($search) {
            $sql .= " AND (p.name LIKE ? OR p.description LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        // 新的商品排在前面 (降冪排序)
        $sql .= " ORDER BY p.product_id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
    }

// ---------------------------------------------------------
// 2. 處理新增商品 (POST)
// ---------------------------------------------------------
} elseif ($method === 'POST') {
    // 接收 POST 資料
    // 預設從 $_POST 讀取 (支援 multipart/form-data 表單上傳，這是有圖片時的標準做法)
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $price = $_POST['price'] ?? 0;
    $stock_quantity = $_POST['stock_quantity'] ?? 0;
    $category_id = $_POST['category_id'] ?? null;
    $seller_id = $_POST['seller_id'] ?? null; // 必須要有賣家 ID
    $image_url = null;

    // 若不是透過表單上傳 (例如傳送 raw JSON)，則改讀取 php://input
    // 這通常發生在前端使用 fetch 並設定 Content-Type: application/json 時
    if (empty($name) && empty($seller_id)) {
        $data = json_decode(file_get_contents('php://input'), true);
        if ($data) {
             $name = $data['name'] ?? '';
             $description = $data['description'] ?? '';
             $price = $data['price'] ?? 0;
             $stock_quantity = $data['stock_quantity'] ?? 0;
             $category_id = $data['category_id'] ?? null;
             $seller_id = $data['seller_id'] ?? null;
        }
    }

    // 驗證 Category ID 是否存在於資料庫
    if (!empty($category_id) && $category_id > 0) {
        $checkCat = $pdo->prepare("SELECT category_id FROM Category WHERE category_id = ?");
        $checkCat->execute([$category_id]);
        if (!$checkCat->fetch()) {
            $category_id = null; // 找不到該分類，設為 null (未分類)
        }
    } else {
        $category_id = null;
    }

    // 基本資料驗證：必填欄位檢查
    if (!$seller_id || empty($name) || empty($description) || $price <= 0 || $stock_quantity < 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid product details (seller_id, name, description, price, stock_quantity are required).']);
        exit();
    }

    // 設定預設商品狀態
    $status = isset($_POST['status']) ? $_POST['status'] : 'on_shelf';
    // 若庫存 <= 0，強制設為下架 (off_shelf)
    if ($stock_quantity <= 0) $status = 'off_shelf';

    // 處理圖片上傳 (僅當有檔案上傳且無錯誤時)
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/'; // 上傳目錄 (相對於此 script 的位置)
        // 若目錄不存在則建立，設定權限為 777 (可讀寫執行)
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $fileTmpPath = $_FILES['image']['tmp_name'];
        $fileName = $_FILES['image']['name'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps)); // 取得副檔名

        // 允許的圖片格式白名單
        $allowedfileExtensions = array('jpg', 'gif', 'png', 'jpeg', 'webp');
        if (in_array($fileExtension, $allowedfileExtensions)) {
            // 檔名重構：使用 md5(時間戳+檔名) 避免重複與中文檔名亂碼問題
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $dest_path = $uploadDir . $newFileName;
            
            // 將檔案從暫存區移動到目標目錄
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                $image_url = 'backend/' . $dest_path; // 存入資料庫的路徑 (相對路徑)
            }
        }
    }

    // 寫入資料庫
    try {
        $stmt = $pdo->prepare("INSERT INTO Product (seller_id, name, description, price, stock_quantity, category_id, image_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$seller_id, $name, $description, $price, $stock_quantity, $category_id, $image_url, $status]);
        
        // 回傳 ID 與圖片路徑
        echo json_encode(['message' => 'Product created', 'id' => $pdo->lastInsertId(), 'image_url' => $image_url]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }

// ---------------------------------------------------------
// 3. 處理更新商品 (PUT)
// ---------------------------------------------------------
} elseif ($method === 'PUT') {
    // PUT 請求通常是 Raw JSON，所以從 php://input 讀取
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
         http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit();
    }

    // 取得 Product ID，優先從 URL 參數拿 (RESTful 風格)，若無則從 JSON body 找
    $product_id = $_GET['id'] ?? ($data['product_id'] ?? null);
    
    // 如果 URL 有 id 但 body 用不同名稱 (有些前端庫的行為)，這裡做個保險
    if (isset($_GET['id']) && (!isset($data['product_id']))) {
        $product_id = $data['product_id'] ?? $_GET['id'];
    }

    $seller_id = $data['seller_id'];

    // 驗證權限：確認該商品確實存在且屬於這個賣家
    $stmt = $pdo->prepare("SELECT seller_id FROM Product WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product || $product['seller_id'] != $seller_id) {
        // 若找不到商品或賣家 ID 不符，回傳 403 Forbidden
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    // 準備更新資料 (若前端未傳送某欄位，則維持原值)
    $name = isset($data['name']) ? $data['name'] : $product['name'];
    $description = isset($data['description']) ? $data['description'] : $product['description'];
    $price = isset($data['price']) ? $data['price'] : $product['price'];
    $stock = isset($data['stock_quantity']) ? $data['stock_quantity'] : $product['stock_quantity'];
    $category_id = isset($data['category_id']) ? $data['category_id'] : $product['category_id'];
    
    // 驗證分類 ID (更新時也需要檢查)
    if (!empty($category_id) && $category_id > 0) {
        $checkCat = $pdo->prepare("SELECT category_id FROM Category WHERE category_id = ?");
        $checkCat->execute([$category_id]);
        if (!$checkCat->fetch()) {
            $category_id = null;
        }
    } else {
        $category_id = null;
    }
    $status = isset($data['status']) ? $data['status'] : $product['status'];

    // 業務規則：若是庫存 <= 0，狀態強制設為下架
    if ($stock <= 0) {
        $status = 'off_shelf';
    }

    // 執行 SQL 更新指令
    $stmt = $pdo->prepare("UPDATE Product SET name=?, description=?, price=?, stock_quantity=?, category_id=?, status=? WHERE product_id=?");
    $stmt->execute([$name, $description, $price, $stock, $category_id, $status, $product_id]);
    
    echo json_encode(['message' => 'Product updated']);

// ---------------------------------------------------------
// 4. 處理刪除商品 (DELETE)
// ---------------------------------------------------------
} elseif ($method === 'DELETE') {
    // 取得 URL 參數中的 ID
    $id = $_GET['id'] ?? null;
    $seller_id = $_GET['seller_id'] ?? null;

    if (!$id || !$seller_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing product ID or seller ID']);
        exit;
    }

    // 執行軟刪除 (Soft Delete)
    // 不真的從資料庫 DROP row，而是將 is_deleted 設為 1
    // 必須同時驗證 product_id 和 seller_id 以確保不會刪到別人的商品
    $stmt = $pdo->prepare("UPDATE Product SET is_deleted = 1 WHERE product_id = ? AND seller_id = ?");
    $stmt->execute([$id, $seller_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['message' => 'Product deleted']);
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Product not found or unauthorized']);
    }
}
?>