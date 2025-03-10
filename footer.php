<?php
// [BLOCK-FOOTER-001]
?>
<!-- Bootstrap RTL JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>
<script>
    // اطمینان از کار کردن دراپ‌داون‌ها
    $(document).ready(function () {
        $('.dropdown-toggle').dropdown();
    });

    // مدیریت منوی کناری
    document.addEventListener('DOMContentLoaded', () => {
        const sidebarToggle = document.querySelector('#sidebarToggle');
        const sidebar = document.querySelector('.sidebar');

        sidebarToggle.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('open');
            } else {
                sidebar.classList.toggle('collapsed');
                document.cookie = `side_nav_collapsed=${sidebar.classList.contains('collapsed') ? '1' : '0'}; path=/`;
            }
        });
    });
</script>
</body>

</html>