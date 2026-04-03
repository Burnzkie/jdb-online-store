/**
 * dashboard_sidebar.js
 * Handles sidebar collapse (desktop) and slide-in/out (mobile).
 */
(function () {
    'use strict';

    const sidebar       = document.getElementById('sidebar');
    const mainContent   = document.getElementById('mainContent');
    const toggleBtn     = document.getElementById('sidebarToggle');
    const overlay       = document.getElementById('sidebarOverlay');
    const closeBtn      = document.getElementById('closeSidebar');

    if (!sidebar) return; // Guard: sidebar not present on page

    // ── Helpers ──────────────────────────────────────────────

    function isMobile() {
        return window.innerWidth < 992;
    }

    function openMobileSidebar() {
        sidebar.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden'; // prevent background scroll
    }

    function closeMobileSidebar() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    function toggleDesktopSidebar() {
        sidebar.classList.toggle('collapsed');
        mainContent?.classList.toggle('expanded');
    }

    // ── Resize handler ────────────────────────────────────────

    function handleResize() {
        if (!isMobile()) {
            // Desktop: ensure mobile classes are cleared
            closeMobileSidebar();
        } else {
            // Mobile: ensure desktop collapse classes are cleared
            sidebar.classList.remove('collapsed');
            mainContent?.classList.remove('expanded');
        }
    }

    // ── Events ────────────────────────────────────────────────

    toggleBtn?.addEventListener('click', () => {
        if (isMobile()) {
            sidebar.classList.contains('active')
                ? closeMobileSidebar()
                : openMobileSidebar();
        } else {
            toggleDesktopSidebar();
        }
    });

    overlay?.addEventListener('click', closeMobileSidebar);
    closeBtn?.addEventListener('click', closeMobileSidebar);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            closeMobileSidebar();
        }
    });

    window.addEventListener('resize', handleResize);

    // ── Init ──────────────────────────────────────────────────
    handleResize();
}());