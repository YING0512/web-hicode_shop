<?php
// 引入資料庫連線設定
require 'db.php';

// 設定回應內容為 JSON 格式
header('Content-Type: application/json');

// 取得 HTTP 請求方法
$method = $_SERVER['REQUEST_METHOD'];
// 讀取 JSON input (從 Request Body)
$data = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST') {
    // 取得 URL 參數中的操作動作 actsion (例如 ?action=register)
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    // ----------------------------------------------------------------
    // 1. 使用者註冊 (Register)
    // ----------------------------------------------------------------
    if ($action === 'register') {
        $username = $data['username'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        
        // 簡單驗證：檢查必填欄位
        if (!$username || !$email || !$password) {
            http_response_code(400); // 400 Bad Request
            echo json_encode(['error' => 'Missing fields (username, email, password)']);
            exit();
        }

        // 密碼雜湊處理 (使用 PHP 預設演算法，通常是 bcrypt)
        // 這是為了安全性，絕對不能在資料庫儲存明文密碼
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            // 寫入使用者資料到資料庫
            $stmt = $pdo->prepare("INSERT INTO User (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hash]);
            
            // 回傳成功訊息與新用戶 ID
            echo json_encode(['message' => 'User registered successfully', 'user_id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            // 處理重複 Email 或其他資料庫錯誤 (例如 Unique Constraint Violation)
            http_response_code(400);
            echo json_encode(['error' => 'User already exists or other database error']);
        }

    // ----------------------------------------------------------------
    // 2. 使用者登入 (Login)
    // ----------------------------------------------------------------
    } elseif ($action === 'login') {
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        // 根據 Email 查找使用者
        $stmt = $pdo->prepare("SELECT * FROM User WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 驗證使用者存在且密碼正確 (比對明文密碼與資料庫中的雜湊值)
        if ($user && password_verify($password, $user['password_hash'])) {
            // 登入成功
            // 在實際生產環境中，這裡通常會生成並回傳 JWT (JSON Web Token) 或 Session ID
            // 這裡為了簡化，直接回傳使用者資訊
            unset($user['password_hash']); // 重要！移除密碼雜湊，避免外洩
            echo json_encode(['message' => 'Login successful', 'user' => $user]);
        } else {
            // 登入失敗 (帳號或密碼錯誤)
            http_response_code(401); // 401 Unauthorized
            echo json_encode(['error' => 'Invalid credentials']);
        }
    }
}
?>
