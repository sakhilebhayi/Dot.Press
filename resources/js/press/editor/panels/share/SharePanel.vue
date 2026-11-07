<script setup>
// Share: who can open the deck (a four step rail), the public link and its settings, the people added by hand, who has access
// right now, and an honest note on how simultaneous editing works. Needs the `manage` ability to change anything; everyone
// can read who has access.
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, EmptyState, InlineNotice, PanelSection, ProgressBead, SelectMenu, Toggle } from '../../../ui/controls';
import VisibilityRail from './VisibilityRail.vue';
import { usePeople } from '../comments/usePeople.js';
import {
    COLLAB_HELP, LEVELS, LINK_LIMITS, ROLES, TEAM_ROLES, accessSummary, dateInputValue, expiryIso, expiryProblem, failureText, identityProblem, levelFor, linkState,
    parseIdentity, passwordProblem, roleLabel, sourceLabel, visibilityFor,
} from './sharingModel.js';

const editor = useEditorContext();
const rootEl = ref(null);
/** The drawer keeps its scroll position between panels; a panel starts at its top. */
onMounted(() => rootEl.value?.closest('.ph')?.scrollTo({ top: 0 }));
const peopleStore = usePeople(editor);
const canManage = computed(() => editor.can.manage === true);
const base = `/api/decks/${editor.deckId}/sharing`;

const share = ref(null);
const loading = ref(false);
const loadError = ref('');
const problem = ref('');
const busy = ref(false);

async function load() {
    if (!canManage.value) return;
    loading.value = true;
    loadError.value = '';
    try { share.value = await editor.api.get(base); } catch (e) { loadError.value = failureText(e); } finally { loading.value = false; }
}
onMounted(() => { load(); peopleStore.refresh(); });

const people = computed(() => share.value?.people || []);
const level = computed(() => (share.value ? levelFor(share.value.visibility, people.value.length) : 'private'));
const levelInfo = computed(() => LEVELS.find((l) => l.value === level.value));
const link = computed(() => share.value?.link || null);
const ls = computed(() => linkState(link.value));

/** Every change goes through here: one request, the server's answer replaces the local copy. */
async function apply(fn, doneWord) {
    busy.value = true;
    problem.value = '';
    try {
        const res = await fn();
        if (res && res.visibility) share.value = res;
        else if (res && res.people) share.value = { ...share.value, people: res.people };
        peopleStore.refresh();
        if (doneWord) editor.announce(doneWord);
        return true;
    } catch (e) {
        problem.value = failureText(e);
        return false;
    } finally { busy.value = false; }
}

// ---- visibility ------------------------------------------------------------------------------------------
const confirmLeaveLink = ref(null); // the level wanted after leaving the link
const confirmPrivate = ref(false);
const addInput = ref(null);

async function chooseLevel(next) {
    if (!canManage.value || next === level.value) return;
    const wanted = visibilityFor(next);
    if (share.value.visibility === 'link' && wanted !== 'link') { confirmLeaveLink.value = next; return; }
    await moveTo(next);
}
async function moveTo(next) {
    const wanted = visibilityFor(next);
    if (next === 'private' && people.value.length) { confirmPrivate.value = true; return; }
    if (wanted !== share.value.visibility) {
        const ok = await apply(() => editor.api.put(base, { visibility: wanted }), `Visibility is now ${LEVELS.find((l) => l.value === next).label}`);
        if (!ok) return;
    }
    if (next === 'shared') { await nextTick(); addInput.value?.focus(); }
}
async function leaveLink() {
    const next = confirmLeaveLink.value;
    confirmLeaveLink.value = null;
    await moveTo(next);
}
async function makePrivate() {
    confirmPrivate.value = false;
    busy.value = true;
    problem.value = '';
    try {
        if (share.value.visibility !== 'private') share.value = await editor.api.put(base, { visibility: 'private' });
        for (const p of share.value.people) await editor.api.delete(`${base}/people/${p.user_id}`);
        share.value = await editor.api.get(base);
        peopleStore.refresh();
        editor.announce('The deck is private');
    } catch (e) { problem.value = failureText(e); share.value = await editor.api.get(base).catch(() => share.value); } finally { busy.value = false; }
}

// ---- link ------------------------------------------------------------------------------------------------
const urlBox = ref(null);
async function copyLink() {
    const url = link.value?.url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        editor.toast('Link copied', { kind: 'ok' });
        editor.announce('Link copied');
    } catch {
        urlBox.value?.focus();
        urlBox.value?.select();
        editor.toast('Press Ctrl or Cmd C to copy the selected link', { kind: 'info' });
    }
}
const confirmRotate = ref(false);
async function rotate() {
    confirmRotate.value = false;
    if (await apply(() => editor.api.post(`${base}/rotate-link`), 'The link was replaced')) editor.toast('Link replaced. The old one no longer works.', { kind: 'ok' });
}
const allowDownload = computed({
    get: () => !!link.value?.allow_download,
    set: (v) => { apply(() => editor.api.put(base, { allow_download: !!v }), v ? 'Visitors can save as PDF' : 'Saving as PDF is off'); },
});

const pw = ref('');
const pwError = ref('');
const pwOpen = ref(false);
async function savePassword() {
    pwError.value = passwordProblem(pw.value);
    if (pwError.value) return;
    if (await apply(() => editor.api.put(base, { password: pw.value }), 'Password set')) { pw.value = ''; pwOpen.value = false; }
}
async function removePassword() {
    if (await apply(() => editor.api.put(base, { password: null }), 'Password removed')) { pw.value = ''; pwOpen.value = false; }
}

const expiry = ref('');
const expiryError = ref('');
watch(link, (l) => { expiry.value = l?.expires_at ? dateInputValue(l.expires_at) : ''; }, { immediate: true });
const today = computed(() => dateInputValue(new Date()));
async function saveExpiry() {
    expiryError.value = expiryProblem(expiry.value);
    if (expiryError.value) return;
    await apply(() => editor.api.put(base, { expires_at: expiry.value ? expiryIso(expiry.value) : null }), expiry.value ? 'Expiry date set' : 'Expiry date removed');
}
async function clearExpiry() { expiry.value = ''; expiryError.value = ''; await saveExpiry(); }
const expiryText = computed(() => (link.value?.expires_at ? new Date(link.value.expires_at).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : ''));

// ---- people ----------------------------------------------------------------------------------------------
const who = ref('');
const whoRole = ref('viewer');
const whoError = ref('');
async function addPerson() {
    whoError.value = identityProblem(who.value);
    if (whoError.value) return;
    busy.value = true;
    problem.value = '';
    try {
        const res = await editor.api.post(`${base}/people`, { ...parseIdentity(who.value), role: whoRole.value });
        share.value = { ...share.value, people: res.people };
        who.value = '';
        peopleStore.refresh();
        editor.announce('Person added');
        editor.toast('Person added', { kind: 'ok' });
    } catch (e) {
        // the server's plain sentence belongs next to the field
        whoError.value = e?.status === 422 ? e.message : '';
        if (!whoError.value) problem.value = failureText(e);
    } finally { busy.value = false; }
}
function changeRole(p, role) {
    if (role === p.role) return;
    apply(() => editor.api.patch(`${base}/people/${p.user_id}`, { role }), `${p.name} is now ${roleLabel(role)}`);
}
async function removePerson(p) {
    busy.value = true;
    problem.value = '';
    try {
        await editor.api.delete(`${base}/people/${p.user_id}`);
        share.value = { ...share.value, people: share.value.people.filter((x) => x.user_id !== p.user_id) };
        peopleStore.refresh();
        editor.announce(`${p.name} was removed`);
        editor.toast(`${p.name} removed`, { kind: 'ok' });
    } catch (e) { problem.value = failureText(e); } finally { busy.value = false; }
}

const access = computed(() => peopleStore.people.value);
const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
</script>

<template>
    <div ref="rootEl" class="shp">
        <p v-if="!canManage" class="shp-pad pr-small">Only an owner or an administrator can change who can open this deck. You can see who has access below.</p>

        <div v-if="canManage && loading && !share" class="shp-pad"><ProgressBead :value="null" label="Loading sharing" /></div>
        <div v-else-if="canManage && loadError && !share" class="shp-pad">
            <InlineNotice kind="error" title="Sharing did not load">{{ loadError }}</InlineNotice>
            <button type="button" class="pr-btn pr-btn--sm" @click="load">Try again</button>
        </div>

        <template v-else-if="canManage && share">
            <div v-if="problem" class="shp-pad shp-pad--tight"><InlineNotice kind="error">{{ problem }}</InlineNotice></div>

            <PanelSection id="share.who" title="Who can open this deck" :state="levelInfo.label" :collapsible="false">
                <VisibilityRail :model-value="level" :busy="busy" :disabled="busy" @update:model-value="chooseLevel" />
                <p class="shp-long" role="status">{{ levelInfo.long }}</p>
            </PanelSection>

            <PanelSection v-if="share.visibility === 'link'" id="share.link" title="Public link" :state="ls.key === 'expired' ? 'expired' : (ls.protected ? 'password' : 'on')" :collapsible="false">
                <InlineNotice v-if="ls.key === 'expired'" kind="warn" title="This link has expired">Nobody can open it. Set a later date or remove the expiry to turn it back on.</InlineNotice>
                <div class="shp-url">
                    <input ref="urlBox" class="pr-field shp-url-in" readonly :value="link.url" aria-label="Public link" @focus="$event.target.select()" />
                    <button type="button" class="pr-btn" @click="copyLink">Copy link</button>
                </div>
                <div class="shp-line">
                    <button type="button" class="shp-link" :disabled="busy" @click="confirmRotate = true">Replace the link</button>
                    <span class="c-hint">Makes a new address and stops the old one.</span>
                </div>

                <div class="shp-block">
                    <p class="c-label">Password</p>
                    <template v-if="link.has_password && !pwOpen">
                        <p class="shp-row"><span class="pr-bead pr-bead--sm" data-state="solid" aria-hidden="true"></span>Visitors need a password.</p>
                        <div class="shp-line"><button type="button" class="shp-link" @click="pwOpen = true">Change</button><button type="button" class="shp-link" :disabled="busy" @click="removePassword">Remove</button></div>
                    </template>
                    <template v-else-if="pwOpen || !link.has_password">
                        <div class="shp-url">
                            <input v-model="pw" type="password" class="pr-field" autocomplete="new-password" aria-label="New link password" placeholder="At least 6 characters" :aria-invalid="!!pwError" @keydown.enter.prevent="savePassword" />
                            <button type="button" class="pr-btn" :disabled="busy || !pw" @click="savePassword">Set</button>
                        </div>
                        <p v-if="pwError" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ pwError }}</p>
                        <p v-else class="c-hint">Without a password anyone who has the link can open it.</p>
                        <button v-if="link.has_password" type="button" class="shp-link" @click="pwOpen = false; pw = ''; pwError = ''">Cancel</button>
                    </template>
                </div>

                <div class="shp-block">
                    <label class="c-label" for="sp-exp">Expires after</label>
                    <div class="shp-url">
                        <input id="sp-exp" v-model="expiry" type="date" class="pr-field" :min="today" :aria-invalid="!!expiryError" @change="saveExpiry" />
                        <button v-if="link.expires_at" type="button" class="pr-btn" :disabled="busy" @click="clearExpiry">Remove</button>
                    </div>
                    <p v-if="expiryError" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ expiryError }}</p>
                    <p v-else class="c-hint">{{ expiryText ? `The link works through ${expiryText}.` : `No expiry. The link works until you turn it off or replace it.` }}</p>
                </div>

                <Toggle v-model="allowDownload" label="Let visitors save as PDF" hint="Adds a print button to the shared page. Visitors save the slides as a PDF from their browser. Speaker notes, hidden slides and comments are never included." :disabled="busy" />
                <p class="shp-limits"><span class="pr-state">visitors see</span>{{ LINK_LIMITS }}</p>
            </PanelSection>

            <PanelSection id="share.people" title="People you add" :state="String(people.length)" :collapsible="false">
                <form class="shp-add" @submit.prevent="addPerson">
                    <div class="shp-add-r">
                        <input ref="addInput" v-model="who" class="pr-field" type="text" inputmode="email" autocomplete="off" placeholder="E-mail or user id" aria-label="E-mail address or user id of the person to add" :aria-invalid="!!whoError" :aria-describedby="whoError ? 'sp-who-err' : undefined" />
                    </div>
                    <div class="shp-add-r">
                        <SelectMenu v-model="whoRole" :options="ROLES" label="Role for the new person" hide-label />
                        <button type="submit" class="pr-btn pr-btn--primary" :disabled="busy || !who.trim()">Add</button>
                    </div>
                    <p v-if="whoError" id="sp-who-err" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ whoError }}</p>
                    <p v-else class="c-hint">Only people who already have an account can be added.</p>
                </form>
                <EmptyState v-if="!people.length" title="Nobody added yet." body="People you add can open the deck even when it is private." compact />
                <ul v-else class="shp-people">
                    <li v-for="p in people" :key="p.user_id" class="shp-person">
                        <span class="pr-avatar shp-av" aria-hidden="true">{{ initials(p.name) }}</span>
                        <span class="shp-pn"><span class="shp-pname">{{ p.name }}</span><span class="shp-pmail">{{ p.email }}</span></span>
                        <SelectMenu :model-value="p.role" :options="ROLES" :label="`Role of ${p.name}`" hide-label :disabled="busy" :width="168" @update:model-value="changeRole(p, $event)" />
                        <button type="button" class="shp-x" :aria-label="`Remove ${p.name}`" :disabled="busy" @click="removePerson(p)">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                        </button>
                    </li>
                </ul>
            </PanelSection>
        </template>

        <PanelSection id="share.access" title="Who has access" :state="access.length ? String(access.length) : ''" :collapsible="canManage">
            <p v-if="access.length" class="c-hint shp-sum">{{ accessSummary(access) }}</p>
            <p v-if="peopleStore.status.value === 'loading' && !access.length" class="pr-small pr-muted">Loading people</p>
            <InlineNotice v-else-if="peopleStore.status.value === 'error' && !access.length" kind="error">{{ peopleStore.error.value }} <button type="button" class="shp-link" @click="peopleStore.refresh()">Try again</button></InlineNotice>
            <ul v-else class="shp-people shp-people--ro">
                <li v-for="p in access" :key="p.id" class="shp-person shp-person--ro">
                    <span class="pr-avatar shp-av" aria-hidden="true">{{ initials(p.name) }}</span>
                    <span class="shp-pn"><span class="shp-pname">{{ p.name }}<span v-if="p.you" class="shp-you"> (you)</span></span><span class="shp-pmail">{{ sourceLabel(p.source) }}<template v-if="p.email">, {{ p.email }}</template></span></span>
                    <span class="pr-state shp-role">{{ roleLabel(p.role) }}</span>
                </li>
            </ul>
            <p v-if="share && share.visibility === 'link'" class="c-hint">Anyone with the public link can also view the slides. They are not listed here.</p>
        </PanelSection>

        <PanelSection id="share.roles" title="What each role can do" :default-open="false">
            <dl class="shp-dl">
                <template v-for="r in ROLES" :key="r.value"><dt>{{ r.label }}</dt><dd>{{ r.hint }}. Added by hand, on any step except Private.</dd></template>
            </dl>
            <p class="c-label shp-sub">Team roles on a Team deck</p>
            <dl class="shp-dl">
                <template v-for="t in TEAM_ROLES" :key="t.team"><dt>{{ t.team }}<span class="pr-state"> become {{ t.gets }}</span></dt><dd>{{ t.can }}</dd></template>
            </dl>
            <p class="c-hint">A role you add raises what someone gets. It never lowers a team role. On a private deck the team gets nothing.</p>
        </PanelSection>

        <PanelSection id="share.how" title="How collaboration works here" :default-open="true">
            <ul class="shp-help">
                <li v-for="line in COLLAB_HELP" :key="line">{{ line }}</li>
            </ul>
        </PanelSection>

        <Dialog :open="!!confirmLeaveLink" title="Turn the public link off?" size="sm" @update:open="confirmLeaveLink = null">
            <p class="pr-small">The link stops working at once for everyone who has it. If you turn it on again later, it gets a new address.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="close">Keep the link</button>
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" data-autofocus @click="leaveLink">Turn it off</button>
            </template>
        </Dialog>
        <Dialog :open="confirmRotate" title="Replace the link?" size="sm" @update:open="confirmRotate = false">
            <p class="pr-small">A new address is made and the old one stops working at once. Send the new link to the people who should keep access.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" data-autofocus @click="rotate">Replace the link</button>
            </template>
        </Dialog>
        <Dialog :open="confirmPrivate" title="Make this deck private?" size="sm" @update:open="confirmPrivate = false">
            <p class="pr-small">{{ people.length === 1 ? 'The person you added loses' : `The ${people.length} people you added lose` }} access. Only you will be able to open the deck.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" data-autofocus @click="makePrivate">Remove them and make it private</button>
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.shp { display: flex; flex-direction: column; padding-bottom: 24px; }
.shp-pad { padding: 14px 16px; display: grid; gap: 12px; justify-items: start; margin: 0; }
.shp-pad--tight { padding: 12px 16px 0; justify-items: stretch; }
.shp-sum { margin: 0; }
.shp-long { margin: 4px 0 0; font-size: var(--t-sm); line-height: 1.5; color: var(--ink-2); }
.shp-url { display: flex; gap: 8px; align-items: center; }
.shp-url .pr-field { flex: 1; min-width: 0; font-size: var(--t-sm); height: 36px; }
.shp-url-in { font-family: var(--f-mono); }
.shp-line { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 14px; }
.shp-link { min-height: 28px; padding: 0; border: 0; background: none; color: var(--ink); font-size: var(--t-sm); font-weight: 500; text-decoration: underline; text-decoration-color: var(--line-strong); text-underline-offset: 3px; }
.shp-link:hover:not(:disabled) { text-decoration-color: var(--ink); }
.shp-link:disabled { opacity: 0.5; }
.shp-block { display: grid; gap: 6px; }
.shp-block .c-label { margin: 0; }
.shp-row { margin: 0; display: flex; align-items: center; gap: 8px; font-size: var(--t-sm); }
.shp-limits { margin: 0; padding: 8px 10px; border-left: 2px solid var(--line-strong); background: var(--raised); font-size: var(--t-sm); line-height: 1.45; color: var(--ink-2); }
.shp-limits .pr-state { display: block; color: var(--muted); margin-bottom: 2px; }
.shp-add { display: grid; gap: 8px; }
.shp-add-r { display: flex; gap: 8px; align-items: stretch; }
.shp-add-r > :first-child { flex: 1; min-width: 0; }
.shp-add-r .pr-btn { --h: 36px; padding: 0 18px; flex: none; }
.shp-add .pr-field { height: 36px; font-size: var(--t-sm); }
.shp-people { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
.shp-person { display: grid; grid-template-columns: 28px minmax(0, 1fr) auto auto; align-items: center; gap: 8px; min-height: 48px; padding: 4px 0; border-top: 1px solid var(--line); }
.shp-person:first-child { border-top: 0; }
.shp-person--ro { grid-template-columns: 28px minmax(0, 1fr) auto; }
.shp-av { --s: 28px; border: 0; color: var(--ink); }
.shp-pn { display: grid; min-width: 0; line-height: 1.25; }
.shp-pname { font-size: var(--t-sm); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.shp-pmail { font-size: var(--t-xs); color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.shp-you { font-weight: 400; color: var(--muted); }
.shp-role { color: var(--ink-2); }
.shp-x { display: inline-grid; place-items: center; width: 32px; height: 32px; border: 1px solid transparent; border-radius: var(--r-2); background: transparent; color: var(--ink-2); }
.shp-x:hover:not(:disabled) { background: var(--raised); border-color: var(--line); color: var(--bad); }
.shp-x:disabled { opacity: 0.4; }
.shp-dl { margin: 0; display: grid; gap: 6px; }
.shp-dl dt { font-size: var(--t-sm); font-weight: 600; margin: 0; }
.shp-dl dt .pr-state { font-weight: 500; color: var(--muted); }
.shp-dl dd { margin: 0 0 6px; font-size: var(--t-sm); color: var(--ink-2); line-height: 1.45; }
.shp-sub { margin: 4px 0 0; }
.shp-help { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
.shp-help li { position: relative; padding-left: 16px; font-size: var(--t-sm); line-height: 1.5; color: var(--ink-2); }
.shp-help li::before { content: ''; position: absolute; left: 1px; top: 0.55em; width: 6px; height: 6px; border: 1.25px solid var(--ink); border-radius: 50%; }
@media (pointer: coarse) { .shp-x { width: 44px; height: 44px; } .shp-link { min-height: 44px; } .shp-person { min-height: 56px; } .shp-url .pr-field, .shp-add .pr-field { height: 44px; } }
</style>
