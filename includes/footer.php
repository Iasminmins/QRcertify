</div><!-- /#main-content -->

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT DE LAYOUT
     ══════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    const sidebar      = document.getElementById('sidebar');
    const overlay      = document.getElementById('sidebar-overlay');
    const mainContent  = document.getElementById('main-content');
    const toggleBtn    = document.getElementById('sidebar-toggle');
    const MOBILE_BP    = 1024; // px (lg breakpoint)

    // ── Estado persistente ───────────────────────────────────
    let isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';

    // ── Aplica estado inicial (desktop) ──────────────────────
    function applyDesktop() {
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
        } else {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('sidebar-collapsed');
        }
    }

    // ── Toggle sidebar ───────────────────────────────────────
    function toggleSidebar() {
        if (window.innerWidth < MOBILE_BP) {
            // Mobile: slide in/out com overlay
            const isOpen = sidebar.classList.toggle('mobile-open');
            overlay.classList.toggle('active', isOpen);
            document.body.style.overflow = isOpen ? 'hidden' : '';
        } else {
            // Desktop: colapsa / expande
            isCollapsed = !isCollapsed;
            localStorage.setItem('sidebar-collapsed', isCollapsed);
            applyDesktop();
        }
    }

    // ── Fecha ao clicar no overlay ───────────────────────────
    overlay.addEventListener('click', function () {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });

    // ── Fecha ao pressionar ESC ──────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    });

    // ── Toggle no botão do header ────────────────────────────
    if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);

    // ── Ajuste no resize ────────────────────────────────────
    window.addEventListener('resize', function () {
        if (window.innerWidth >= MOBILE_BP) {
            sidebar.classList.remove('mobile-open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            applyDesktop();
        }
    });

    // ── Inicializa ───────────────────────────────────────────
    applyDesktop();

    // ── Tooltip nos nav-links colapsados ────────────────────
    document.querySelectorAll('.nav-link').forEach(function (link) {
        const label = link.querySelector('.nav-label');
        if (!label) return;
        link.setAttribute('title', label.textContent.trim());
    });

    // ── Fecha sidebar mobile ao navegar ─────────────────────
    document.querySelectorAll('#sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < MOBILE_BP) {
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

})();
</script>

</body>
</html>
