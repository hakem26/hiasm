</div>
<!-- پایان main-container -->


<!-- Bootstrap RTL JS -->
<script src="assets/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"></script>
<script>
    // اطمینان از لود شدن بوت‌استرپ و فعال‌سازی دراپ‌داون‌ها
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
            const dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(dropdown => {
                new bootstrap.Dropdown(dropdown);
            });
        } else {
            console.error('Bootstrap Dropdown is not available.');
        }
    });
</script>
</body>

</html>