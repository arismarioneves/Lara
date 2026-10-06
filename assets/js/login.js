import { api } from './api.js';

const form = document.getElementById('login');
const error = form.querySelector('.error');

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.hidden = true;
    const button = form.querySelector('button');
    button.disabled = true;
    try {
        await api.login(form.password.value);
        location.reload();
    } catch (err) {
        error.textContent = err.message;
        error.hidden = false;
        button.disabled = false;
    }
});
