const Auth = {
    login: (user, token = null) => {
        if (token) localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(user));
    },

    logout: () => {
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        window.location.href = 'login.html';
    },

    getUser: () => {
        const user = localStorage.getItem('user');
        return user ? JSON.parse(user) : null;
    },

    isAuthenticated: () => {
        // React app relies on 'user' object existence
        return !!localStorage.getItem('user');
    },

    checkAuth: () => {
        if (!Auth.isAuthenticated()) {
            window.location.href = 'login.html';
            return false;
        }
        return true;
    }
};
