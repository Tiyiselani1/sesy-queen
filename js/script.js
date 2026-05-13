document.addEventListener('DOMContentLoaded', () => {
    // Dark Mode Toggle
    const darkModeToggle = document.getElementById('darkModeToggle');
    const body = document.getElementById('body');
    darkModeToggle.addEventListener('click', () => {
        body.classList.toggle('dark');
        localStorage.setItem('darkMode', body.classList.contains('dark'));
    });
    if (localStorage.getItem('darkMode') === 'true') {
        body.classList.add('dark');
    }
});