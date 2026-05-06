document.addEventListener('DOMContentLoaded', function () {
    // Focus username if server returned an error
    var form = document.getElementById('loginForm');
    if (!form) return;

    if (form.dataset.hasError === '1') {
        document.getElementById('username').focus();
    }

    // Password show/hide — attached via data-toggle-password on buttons
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.dataset.togglePassword);
            if (!input) return;
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            var icon = btn.querySelector('i');
            if (icon) {
                icon.classList.replace(
                    isHidden ? 'fa-eye' : 'fa-eye-slash',
                    isHidden ? 'fa-eye-slash' : 'fa-eye'
                );
            }
            btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });

    // Form validation
    form.addEventListener('submit', function (e) {
        var username = document.getElementById('username');
        var password = document.getElementById('password');
        var passError = document.getElementById('password-error');
        var firstInvalid = null;

        if (!username.value.trim()) {
            username.classList.add('is-invalid');
            firstInvalid = firstInvalid || username;
        } else {
            username.classList.remove('is-invalid');
        }

        if (!password.value) {
            password.classList.add('is-invalid');
            if (passError) {
                passError.textContent = 'Please enter your password.';
                passError.style.display = 'block';
            }
            firstInvalid = firstInvalid || password;
        } else {
            password.classList.remove('is-invalid');
            if (passError) passError.style.display = 'none';
        }

        if (firstInvalid) {
            e.preventDefault();
            firstInvalid.focus();
        }
    });
});
