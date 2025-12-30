<?php
// 引入資料庫連線設定
require 'db.php';
// 設定回應內容為 JSON 格式
header('Content-Type: application/json');

// ----------------------------------------------------------------
// 簡易管理員權限檢查
// ----------------------------------------------------------------
// 檢查管理員身份的輔助函數
// 回傳: true (是管理員) / false (不是管理員)
function checkAdmin($pdo, $user_id) {
    if (!$user_id) return false;
    // 查詢使用者角色
    $stmt = $pdo->prepare("SELECT role FROM User WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    return ($user && $user['role'] === 'admin');
}

// 取得 HTTP 請求方法
$method = $_SERVER['REQUEST_METHOD'];

// ----------------------------------------------------------------
// 1. 處理讀取請求 (GET) - 列出所有兌換代碼
// ----------------------------------------------------------------
if ($method === 'GET') {
    $admin_id = $_GET['admin_id'] ?? null;
    
    // 驗證管理員權限
    if (!checkAdmin($pdo, $admin_id)) {
         http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit;
    }

    // 列出代碼 (顯示當前使用次數與上限)
    $stmt = $pdo->query("SELECT * FROM RedemptionCode ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll());

// ----------------------------------------------------------------
// 2. 處理新增請求 (POST) - 建立新的兌換代碼
// ----------------------------------------------------------------
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $admin_id = $data['admin_id'] ?? null;
    
    // 驗證管理員權限
    if (!checkAdmin($pdo, $admin_id)) {
         http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit;
    }

    // 讀取代碼資訊
    $code = $data['code'] ?? '';
    $value = $data['value'] ?? 0;        // 代碼價值 (例如 100 元)
    $max_uses = $data['max_uses'] ?? 1; // 最大使用次數 (預設 1)

    // 基本驗證
    if (empty($code) || $value <= 0 || $max_uses < 1) {
        http_response_code(400); echo json_encode(['error' => 'Invalid code, value, or usage limit']); exit;
    }

    try {
        // 寫入代碼資料表 (RedemptionCode)
        $stmt = $pdo->prepare("INSERT INTO RedemptionCode (code, value, max_uses) VALUES (?, ?, ?)");
        $stmt->execute([$code, $value, $max_uses]);
        echo json_encode(['message' => 'Created code successfully', 'code' => $code]);

    } catch (PDOException $e) {
        // 錯誤處理：若代碼已存在 (Unique Constraint)
        if ($e->getCode() == 23000) {
             http_response_code(400); echo json_encode(['error' => 'Code already exists']);
        } else {
             http_response_code(500); echo json_encode(['error' => 'Database error']);
        }
    }

// ----------------------------------------------------------------
// 3. 處理刪除請求 (DELETE) - 刪除代碼
// ----------------------------------------------------------------
} elseif ($method === 'DELETE') {
    $admin_id = $_GET['admin_id'] ?? null;
    $code_id = $_GET['code_id'] ?? null;

    // 驗證管理員權限
    if (!checkAdmin($pdo, $admin_id)) {
        http_response_code(403); echo json_encode(['error' => 'Unauthorized']); exit;
    }

    // 刪除代碼
    $stmt = $pdo->prepare("DELETE FROM RedemptionCode WHERE code_id = ?");
    $stmt->execute([$code_id]);
    echo json_encode(['message' => 'Deleted']);
}
?>
