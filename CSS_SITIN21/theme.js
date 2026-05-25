(function () {
    var storageKey = "ccs_theme";

    function getSavedTheme() {
        try {
            return localStorage.getItem(storageKey) || "light";
        } catch (e) {
            return "light";
        }
    }

    function setTheme(theme) {
        var root = document.documentElement;
        root.setAttribute("data-theme", theme);

        try {
            localStorage.setItem(storageKey, theme);
        } catch (e) {
            // Ignore storage errors in restricted browsing modes.
        }

        var button = document.querySelector(".theme-toggle-btn");
        if (!button) return;

        var icon = button.querySelector(".theme-toggle-icon");
        var text = button.querySelector(".theme-toggle-text");
        var darkMode = theme === "dark";

        if (icon) icon.textContent = darkMode ? "☀" : "🌙";
        if (text) text.textContent = darkMode ? "Light" : "Dark";
        button.setAttribute("aria-label", darkMode ? "Switch to light mode" : "Switch to dark mode");
        button.setAttribute("title", darkMode ? "Switch to light mode" : "Switch to dark mode");
    }

    function initToggle() {
        var button = document.querySelector(".theme-toggle-btn");
        if (!button) return;

        button.addEventListener("click", function () {
            var current = document.documentElement.getAttribute("data-theme") || "light";
            setTheme(current === "dark" ? "light" : "dark");
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        setTheme(getSavedTheme());
        initToggle();
    });
})();
