<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    pending: { type: Array, default: () => [] },
    reviewed: { type: Array, default: () => [] },
});

const rejectingId = ref(null);
const rejectForm = useForm({ reason: '' });

function approve(proposal) {
    useForm({}).post(`/operator/dependency-patches/${proposal.id}/approve`);
}

function promptReject(proposal) {
    rejectingId.value = proposal.id;
    rejectForm.reason = '';
}

function cancelReject() {
    rejectingId.value = null;
}

function confirmReject(proposal) {
    rejectForm.post(`/operator/dependency-patches/${proposal.id}/reject`, {
        onSuccess: () => { rejectingId.value = null; },
    });
}
</script>

<template>
    <AppLayout title="Dependency patches">
        <h2 class="ac-h2">Waiting for a decision <span class="pr-state">{{ pending.length }}</span></h2>

        <div v-if="pending.length" class="ac-op">
            <div v-for="proposal in pending" :key="proposal.id" class="ac-op-item">
                <p class="ac-op-t">{{ proposal.manager }}: {{ proposal.risk_summary }}</p>
                <p class="ac-op-s">Proposed command <code>{{ proposal.proposed_command }}</code></p>
                <ul class="ac-op-adv">
                    <li v-for="(advisory, i) in proposal.advisories" :key="i">
                        {{ advisory.package }} ({{ advisory.severity }}): {{ advisory.title }}
                    </li>
                </ul>

                <div v-if="rejectingId === proposal.id" class="ac-op-do">
                    <label class="pr-sr" :for="`reject-${proposal.id}`">Reason for rejecting</label>
                    <input :id="`reject-${proposal.id}`" v-model="rejectForm.reason" type="text" placeholder="Reason for rejecting" class="pr-field">
                    <button type="button" class="pr-btn pr-btn--danger-solid" @click="confirmReject(proposal)">Reject it</button>
                    <button type="button" class="pr-btn" @click="cancelReject">Cancel</button>
                </div>
                <div v-else class="ac-op-do">
                    <button type="button" class="pr-btn pr-btn--primary" @click="approve(proposal)">Approve</button>
                    <button type="button" class="pr-btn pr-btn--danger-quiet" @click="promptReject(proposal)">Reject</button>
                </div>
            </div>
        </div>
        <p v-else class="ac-empty">Nothing waiting for a decision.</p>

        <h2 class="ac-h2">Recently decided</h2>

        <div v-if="reviewed.length" class="ac-op">
            <div v-for="proposal in reviewed" :key="proposal.id" class="ac-op-item" style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 16px; align-items: center;">
                <span>{{ proposal.manager }}: {{ proposal.risk_summary }}</span>
                <span class="ac-state">
                    <span
                        class="pr-bead pr-bead--sm"
                        :class="{ 'pr-bead--warn': proposal.status === 'rejected' || proposal.status === 'failed' }"
                        :data-state="proposal.status === 'applied' ? 'solid' : proposal.status === 'approved' ? 'current' : undefined"
                        aria-hidden="true"
                    />
                    {{ proposal.status }}
                </span>
            </div>
        </div>
        <p v-else class="ac-empty">Nothing decided yet.</p>
    </AppLayout>
</template>
