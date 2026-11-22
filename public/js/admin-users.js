/* Admin → Staff logins: who can sign in, the name printed on their bills and receipts, and what
   they can use (Auth::ROLES — e.g. a front-desk login only gets the POS screen). The server checks
   the role on every request; this page only manages the logins. admin.js creates this with its helpers. */
window.YDUSERS = function (h) {
  'use strict';
  const { $, esc, icon, fdt, api, toast, setHead, view, openModal, closeModal, modalHead, me } = h;
  let DATA = { users: [], roles: [], minPassword: 10 };
  const roleOf = (k) => DATA.roles.find((r) => r.value === k) || { label: k, about: '' };
  const panel = () => $('[data-modal-panel]');

  async function viewUsers() {
    setHead('Staff logins', 'Who can sign in, and what each login can use.');
    DATA = await api('/users');
    render();
  }

  function render() {
    const mine = (me() || {}).username;
    view.innerHTML = `<div class="users">
      <section class="card">
        <div class="card__head"><h2>Logins</h2><button type="button" class="abtn abtn--primary abtn--sm" data-add>${icon('a-plus', 15)}Add a login</button></div>
        <div class="table-wrap"><table class="tbl users-tbl">
          <thead><tr><th>Name</th><th>Can use</th><th>Last signed in</th><th>Status</th><th></th></tr></thead>
          <tbody>${DATA.users.map((u) => `<tr class="${u.active ? '' : 'is-off'}" data-edit="${u.id}" tabindex="0">
            <td><b>${esc(u.displayName)}</b>${u.username === mine ? ' <span class="src">(you)</span>' : ''}<span class="sub">${esc(u.username)}</span></td>
            <td>${esc(u.roleLabel)}</td>
            <td>${u.lastLogin ? fdt(u.lastLogin) : '<span class="src">Never</span>'}</td>
            <td>${u.active ? '<span class="st st--confirmed">On</span>' : '<span class="st st--cancelled">Switched off</span>'}</td>
            <td class="num"><button type="button" class="abtn abtn--sm" data-edit="${u.id}">Edit</button></td>
          </tr>`).join('')}</tbody>
        </table></div>
      </section>
      <section class="card">
        <div class="card__head"><h2>What each login can use</h2></div>
        <div class="card__body users-roles">${DATA.roles.map((r) => `<div><b>${esc(r.label)}</b><p>${esc(r.about)}</p></div>`).join('')}
          <p class="src">Bills and receipts print the name of the person who made them. Everyone can change their own password in Settings. A login that has been used can't be removed, only switched off, so old bills keep the right name.</p>
        </div>
      </section>
    </div>`;
    view.onclick = (e) => {
      if (e.target.closest('[data-add]')) { form(null); return; }
      const ed = e.target.closest('[data-edit]');
      if (ed) form(DATA.users.find((u) => u.id === Number(ed.dataset.edit)));
    };
    view.onkeydown = (e) => {
      const tr = e.target.closest('tr[data-edit]');
      if (tr && e.key === 'Enter') form(DATA.users.find((u) => u.id === Number(tr.dataset.edit)));
    };
  }

  const roleRadios = (sel, disabled) => `<div class="role-pick" role="radiogroup" aria-label="What this login can use">${DATA.roles.map((r) => `
    <label class="role-pick__item"><input type="radio" name="role" value="${esc(r.value)}" ${r.value === sel ? 'checked' : ''} ${disabled ? 'disabled' : ''} required>
      <span><b>${esc(r.label)}</b><small>${esc(r.about)}</small></span></label>`).join('')}</div>`;

  /** Add a login (u = null) or edit one. */
  function form(u) {
    const self = u && u.username === (me() || {}).username;
    const min = DATA.minPassword;
    const pw = `<label class="fld"><span>${u ? 'New password <small>(leave empty to keep it)</small>' : 'Password'}</span><input type="password" name="password" autocomplete="new-password" minlength="${min}" ${u ? '' : 'required'}><small>At least ${min} characters. Tell them in person, not by message.</small></label>
      <label class="fld"><span>Repeat the password</span><input type="password" name="again" autocomplete="new-password" minlength="${min}" ${u ? '' : 'required'}></label>`;
    openModal(`${modalHead(u ? `Edit ${u.displayName}` : 'Add a login', u ? `Username: ${u.username}` : 'For a member of staff')}<form class="dr-body" data-user-form>
      <label class="fld"><span>Name <small>(printed on bills and receipts)</small></span><input name="name" maxlength="60" value="${esc(u ? u.name : '')}" placeholder="e.g. Kasun" ${u ? '' : 'required'}></label>
      ${u ? '' : '<label class="fld"><span>Username <small>(to sign in)</small></span><input name="username" maxlength="40" required autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="e.g. kasun"><small>3 to 40 letters or numbers, no spaces.</small></label>'}
      <div class="fld"><span>What can they use?</span>${roleRadios(u ? u.role : 'pos', self)}${self ? '<small>You can\'t change your own role. Ask another admin.</small>' : ''}</div>
      ${self ? '<p class="src">Change your own password in <a href="#/settings">Settings</a>.</p>' : pw}
      ${u && !self ? `<label class="chk"><input type="checkbox" name="active" ${u.active ? 'checked' : ''}> Can sign in <small class="src">(switch off when someone leaves — they're signed out at once)</small></label>` : ''}
      <div class="dlg-foot">
        ${u && !self && !u.lastLogin ? '<button type="button" class="abtn abtn--danger" data-remove>Remove</button>' : '<span></span>'}
        <span><button type="button" class="abtn" data-mclose>Cancel</button> <button class="abtn abtn--primary" type="submit">${u ? 'Save' : 'Add login'}</button></span>
      </div>
    </form>`);
    const p = panel();
    p.onsubmit = async (e) => {
      e.preventDefault();
      const f = e.target;
      if (f.password && f.password.value !== f.again.value) { toast('The passwords don\'t match.', true); return; }
      const body = { name: f.name.value.trim() };
      if (!self) body.role = (f.querySelector('[name="role"]:checked') || {}).value;
      if (f.password && f.password.value) body.password = f.password.value;
      if (f.active) body.active = f.active.checked;
      const btn = f.querySelector('[type="submit"]');
      btn.disabled = true;
      try {
        if (u) {
          await api(`/users/${u.id}`, { method: 'PATCH', body });
          toast(`${body.name || u.username} saved${body.password ? ' — the new password works now' : ''}`);
        } else {
          body.username = f.username.value.trim();
          const r = await api('/users', { method: 'POST', body });
          toast(`${r.user.displayName} can now sign in as “${r.user.username}”`);
        }
        closeModal();
        await viewUsers();
      } catch (ex) { toast(ex.message, true); btn.disabled = false; }
    };
    p.onclick = async (e) => {
      if (!e.target.closest('[data-remove]')) return;
      if (!confirm(`Remove the login “${u.username}”? It has never been used.`)) return;
      try {
        await api(`/users/${u.id}`, { method: 'DELETE' });
        toast(`${u.displayName} removed`);
        closeModal();
        await viewUsers();
      } catch (ex) { toast(ex.message, true); }
    };
  }

  return { users: viewUsers };
};
