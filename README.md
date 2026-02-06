# Web Hicode Shop

這是一個集成了現代前端技術與 PHP 後端的全功能電子商務平台。

## ✨ 功能特色

- **用戶系統**
  - 用戶註冊與登錄
  - 角色權限管理（管理員 / 賣家 / 買家）
  
- **商品系統**
  - 商品瀏覽與搜索
  - 商品分類展示
  
- **購物體驗**
  - 購物車管理
  - 訂單結帳流程
  - 訂單歷史與狀態追蹤

- **錢包與支付**
  - 數位錢包餘額管理
  - 兌換碼充值系統
  
- **管理後台**
  - 用戶管理介面
  - 兌換碼生成與管理（支援多次使用與計數）

## 🛠️ 技術對棧

### 前端 (Frontend)
- **核心框架**: [React 19](https://react.dev/)
- **構建工具**: [Vite 7](https://vitejs.dev/)
- **樣式庫**: [Tailwind CSS 4](https://tailwindcss.com/)
- **路由**: React Router DOM

### 後端 (Backend)
- **語言**: PHP (Native)
- **數據庫**: MySQL
- **架構**: RESTful API 風格交互

## 🚀 安裝與運行指南

### 1. 後端設置 (Backend)

確保您的環境已安裝 PHP 和 MySQL (例如使用 XAMPP, WAMP, 或 Docker)。

1. **數據庫配置**:
   - 創建一個名為 `ecommerce_db` 的 MySQL 數據庫。
   - 打開 `backend/db.php`，根據您的環境修改數據庫連接設置 (Host, User, Password)。
     ```php
     $host = '127.0.0.1';
     $db   = 'ecommerce_db';
     $user = 'root';
     $pass = ''; 
     ```

2. **初始化數據**:
   - 根據需要運行初始化腳本 (例如 `update_schema_v7.php` 用於更新數據庫結構，`seed_categories.php` 用於填充初始分類數據)。
   - 確保 `backend/uploads` 目錄具有寫入權限，用於存儲上傳的文件。

3. **啟動伺服器**:
   - 將 `backend` 文件夾放置在您的 Web 伺服器根目錄下，或使用 PHP 內置伺服器：
     ```bash
     cd backend
     php -S localhost:8000
     ```

### 2. 前端設置 (Frontend)

確保您的環境已安裝 Node.js。

1. **進入項目目錄**:
   ```bash
   cd frontend
   ```

2. **安裝依賴**:
   ```bash
   npm install
   ```

3. **啟動開發環境**:
   ```bash
   npm run dev
   ```
   啟動後，瀏覽器通常會自動打開前端頁面 (默認為 `http://localhost:5173`)。前端會向後端 API 發送請求，請確保後端伺服器運行正常並處理跨域 (CORS) 設置。

## 📂 目錄結構說明

- `frontend/`: 包含 React 前端源代碼、Vite 配置及靜態資源。
- `backend/`: 包含 PHP 後端邏輯、API 接口及數據庫連接文件。
- `database/`: 可能包含數據庫導出文件或遷移腳本。
- `assets/`: 項目共用的靜態資源文件。
