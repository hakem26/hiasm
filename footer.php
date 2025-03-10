<?php
// [BLOCK-FOOTER-001]
?>
    </div>
    <!-- پایان محتوای اصلی -->

    <!-- اسکریپت‌ها -->
    <script>
        // [BLOCK-HEADER-003]
        document.addEventListener('DOMContentLoaded', () => {
            const sidebarToggle = document.querySelector('#sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');

            sidebarToggle.addEventListener('click', () => {
                if (window.innerWidth <= 768) {
                    sidebar.classList.toggle('open');
                } else {
                    sidebar.classList.toggle('collapsed');
                    document.cookie = `side_nav_collapsed=${sidebar.classList.contains('collapsed') ? '1' : '0'}; path=/`;
                }
            });

            // تنظیم اولیه بر اساس وضعیت منو
            if (sidebar.classList.contains('collapsed')) {
                mainContent.style.marginRight = '60px';
            } else {
                mainContent.style.marginRight = '200px';
            }

            // تنظیم در موبایل
            if (window.innerWidth <= 768) {
                mainContent.style.marginRight = '0';
                if (sidebar.classList.contains('open')) {
                    mainContent.style.marginRight = '200px';
                }
            }
        });
    </script>
</body>
</html>