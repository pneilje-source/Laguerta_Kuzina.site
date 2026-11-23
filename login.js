$(function () {
    $('#login-form').on('submit', function (event) {
        event.preventDefault();
        $('#login-status')
            .text('Sign-in is not connected yet. Please contact your system administrator.')
            .removeClass('d-none');
    });
});