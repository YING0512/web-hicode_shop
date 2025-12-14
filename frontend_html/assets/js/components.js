// Make renderNavbar async compliant by managing internal state or separated calls
async function renderNavbar() {
    const user = Auth.getUser();
    const navContainer = document.getElementById('navbar-container');
    if (!navContainer) return;

    let links = `
        <a href="index.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">首頁</a>
    `;

    if (user) {
        links += `
            <div class="relative inline-block">
                <a href="orders.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">我的訂單</a>
            </div>
            <div class="relative inline-block">
                <a href="wallet.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">錢包</a>
            </div>
            <div class="relative inline-block">
                <button onclick="window.onOpenCart()" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">購物車</button>
                <span id="badge-cart" class="hidden absolute top-0 right-0 -mt-1 -mr-1 w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
            </div>
            <div class="relative inline-block">
                <a href="chat.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">聊天室</a>
                <span id="badge-chat" class="hidden absolute top-0 right-0 -mt-1 -mr-1 w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
            </div>
            <div class="relative inline-block">
                <a href="seller-dashboard.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">賣家中心</a>
                <span id="badge-seller" class="hidden absolute top-0 right-0 -mt-1 -mr-1 w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
            </div>
            
            <div class="relative ml-3 inline-block">
                <span class="text-gray-300 px-3 py-2 rounded-md text-sm font-medium">你好, ${user.username}</span>
                <button onclick="Auth.logout()" class="text-red-400 hover:text-red-300 px-3 py-2 rounded-md text-sm font-medium ml-2">登出</button>
            </div>
        `;

        // Trigger updates after render
        setTimeout(updateNavbarBadges, 100);
    } else {
        links += `
            <a href="login.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">登入</a>
            <a href="register.html" class="text-gray-300 hover:text-white px-3 py-2 rounded-md text-sm font-medium">註冊</a>
        `;
    }

    navContainer.innerHTML = `
        <nav class="bg-gray-800">
            <div class="w-full px-4 sm:px-6 lg:px-8">
                <div class="relative flex items-center justify-between h-16">
                    <div class="flex-1 flex items-center justify-center sm:items-stretch sm:justify-start">
                        <div class="flex-shrink-0 flex items-center gap-2">
                            <!-- CSS Logo implementation -->
                            <div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center">
                                <span class="text-white font-mono font-bold text-sm tracking-tighter">&lt;/&gt;</span>
                            </div>
                            <a href="index.html" class="text-white font-bold text-xl tracking-tight">Hey!Code</a>
                        </div>
                        <div class="hidden sm:block sm:ml-6">
                            <div class="flex space-x-4 items-center">
                                ${links}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
    `;
}

async function updateNavbarBadges() {
    const user = Auth.getUser();
    if (!user) return;

    // 1. Check Cart
    try {
        const cartData = await fetchApi(`/cart.php?user_id=${user.user_id}`);
        const hasItems = cartData.items && cartData.items.length > 0;
        const badge = document.getElementById('badge-cart');
        if (badge) {
            if (hasItems) badge.classList.remove('hidden');
            else badge.classList.add('hidden');
        }
    } catch (e) { }

    // 2. Check Unread Chat
    try {
        const rooms = await fetchApi(`/chat.php?action=list_rooms&user_id=${user.user_id}`);
        let unreadCount = 0;
        if (Array.isArray(rooms)) {
            unreadCount = rooms.reduce((acc, r) => acc + (parseInt(r.unread_count) || 0), 0);
        }
        const badge = document.getElementById('badge-chat');
        if (badge) {
            if (unreadCount > 0) badge.classList.remove('hidden');
            else badge.classList.add('hidden');
        }
    } catch (e) { }

    // 3. Check Seller Pending Orders (For Seller Dashboard)
    // Only check if user might be a seller (or just check for everyone, backend handles empty return)
    if (user.role === 'seller' || user.role === 'admin') {
        try {
            const orders = await fetchApi(`/orders.php?seller_id=${user.user_id}`);
            let pendingCount = 0;
            if (Array.isArray(orders)) {
                pendingCount = orders.filter(o => o.status === 'PENDING').length;
            }
            const badge = document.getElementById('badge-seller');
            if (badge) {
                if (pendingCount > 0) badge.classList.remove('hidden');
                else badge.classList.add('hidden');
            }
        } catch (e) { }
    }
}

function renderProductCard(product) {
    const imageUrl = product.image_url ? `/1208/${product.image_url}` : "https://via.placeholder.com/400x400?text=無圖片";
    const price = Number(product.price).toFixed(0);

    return `
        <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden flex flex-col hover:shadow-md transition-shadow duration-300 cursor-pointer" onclick="openProductModal(${JSON.stringify(product).replace(/"/g, '&quot;')})">
            <div class="aspect-square w-full bg-gray-100 relative">
                <img
                    src="${imageUrl}"
                    alt="${product.name}"
                    class="h-full w-full object-cover"
                />
            </div>
            <div class="p-4 flex-1 flex flex-col">
                <div class="mb-3">
                    <h3 class="text-lg font-bold text-gray-800 line-clamp-1" title="${product.name}">
                        ${product.name}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">${product.category_name || ''}</p>
                </div>
                <div class="mt-auto flex items-center justify-between">
                    <span class="text-xl font-bold text-blue-600">
                        NT$ ${price}
                    </span>
                    <button
                        onclick="event.stopPropagation(); addToCart(${product.product_id})"
                        class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-2 px-4 rounded transition-colors"
                    >
                        加入購物車
                    </button>
                </div>
            </div>
        </div>
    `;
}
