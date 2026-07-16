/**
 * Formulaire de contact — pré-sélection du motif + envoi AJAX vers POST /contact.
 * CSP stricte : ce fichier est le seul JS de la page (pas d'inline).
 */
(function () {
  'use strict';

  // Pré-sélection du motif quand on arrive par un CTA (partenariat / investisseur).
  document.querySelectorAll('.js-contact').forEach(function (a) {
    a.addEventListener('click', function () {
      var m = a.getAttribute('data-motif');
      var sel = document.getElementById('motif');
      if (m && sel) sel.value = m;
    });
  });

  var form = document.getElementById('contact-form');
  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    var status = document.getElementById('form-status');
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    status.className = 'form-status';
    status.textContent = '';
    try {
      var resp = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json' }
      });
      var data = await resp.json();
      if (resp.ok && data.ok) {
        status.className = 'form-status ok';
        status.textContent = 'Merci ! Votre demande a bien été envoyée — nous revenons vers vous rapidement.';
        form.reset();
      } else if (resp.status === 422 && data.errors) {
        status.className = 'form-status err';
        status.textContent = Object.values(data.errors).join(' ');
      } else {
        status.className = 'form-status err';
        status.textContent = data.error || 'Une erreur est survenue, merci de réessayer.';
      }
    } catch (err) {
      status.className = 'form-status err';
      status.textContent = "Impossible d'envoyer la demande (problème réseau). Réessayez.";
    } finally {
      btn.disabled = false;
    }
  });
})();
