<?php
// 1. 設定跨網域存取
// 因為我們的前端跟後端可能被視為不同來源，這幾行是為了讓瀏覽器允許它們溝通。
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight request (處理預檢請求)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. 定義連線資訊
$host = '127.0.0.1';
$db   = 'ecommerce_db';
$user = 'root';
$pass = ''; // Default XAMPP/WAMP password
$charset = 'utf8mb4';

// 3. 設定 DSN (Data Source Name)
// 這是告訴程式資料庫在哪裡、叫什麼名字字串格式
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// 4. 設定 PDO 連線選項
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// 5. 建立資料庫連線
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // 若連線失敗，回傳 500 錯誤並顯示錯誤訊息
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}
?>