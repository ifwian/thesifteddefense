// staff.js - the pop-up windows of the staff page (View, and the Cancel / Archive / Restore confirmations).
(function () {
  'use strict';
  var detail = document.getElementById('detail-modal');
  var body = document.getElementById('detail-modal-body');
  var confirmBox = document.getElementById('confirm-modal');

  var CONFIRM_TEXT = {
    cancelled: { title: 'Cancel {ref}?', yes: 'Yes, cancel it', cls: 'btn-warn',
      body: 'The reservation will be marked as cancelled and its table will be released. The record is kept in the list.' },
    archive: { title: 'Archive {ref}?', yes: 'Yes, archive it', cls: 'btn-primary',
      body: 'The reservation moves out of the active list into the Archive. Nothing is deleted: you can view it and restore it at any time.' },
    delete: { title: 'Permanently delete {ref}?', yes: 'Yes, delete permanently', cls: 'btn-danger',
      body: 'This removes the archived reservation, its payment record and its uploaded proof from the database. It cannot be undone. Use Restore instead if you may need it again.' },
    restore: { title: 'Restore {ref}?', yes: 'Yes, restore it', cls: 'btn-primary',
      body: 'The reservation returns to the active reservation list with its status and payment unchanged.' }
  };

  var reopenDetail = false;   // was the View window open when a confirmation was opened?
  function openDialog(dialog) { if (!dialog.open) { dialog.showModal(); } }

  // Close buttons, and a click on the dimmed background.
  document.addEventListener('click', function (event) {
    var closeButton = event.target.closest('[data-close]');
    if (closeButton) { closeButton.closest('dialog').close(); return; }
    if (event.target.tagName === 'DIALOG') { event.target.close(); return; }

    // View: load the details of that reservation into the window (no page reload).
    var view = event.target.closest('[data-view]');
    if (view) {
      event.preventDefault();
      body.textContent = 'Loading...';
      openDialog(detail);
      var url = new URL(view.href, location.href);
      url.searchParams.set('modal', '1');
      fetch(url.toString(), { credentials: 'same-origin' })
        .then(function (response) { if (!response.ok) { throw new Error('Could not load details'); } return response.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function () { body.textContent = 'Could not load the details. Please close this window and try again.'; });
      return;
    }

    // Cancel / Archive / Restore / Delete: always ask first.
    var actionButton = event.target.closest('[data-act]');
    if (actionButton) {
      var kind = actionButton.dataset.act;
      var ref = actionButton.dataset.ref;
      var text = CONFIRM_TEXT[kind];
      document.getElementById('confirm-id').value = actionButton.dataset.id;
      document.getElementById('confirm-action').value = kind;
      document.getElementById('confirm-return').value = actionButton.dataset.return || '';
      document.getElementById('confirm-title').textContent = text.title.replace('{ref}', ref);
      document.getElementById('confirm-text').textContent = text.body;
      var yesButton = document.getElementById('confirm-submit');
      yesButton.textContent = text.yes;
      yesButton.className = 'btn btn-small ' + text.cls;
      reopenDetail = detail.open;
      detail.close();
      openDialog(confirmBox);
    }
  });

  // "Go back" / close goes back to the details window instead of leaving the screen empty.
  confirmBox.addEventListener('close', function () { if (reopenDetail) { reopenDetail = false; openDialog(detail); } });

  // After an action the page reloads with ?id=...: show that reservation's window again.
  if (detail && body.querySelector('.st-detail-body')) { openDialog(detail); }
})();
