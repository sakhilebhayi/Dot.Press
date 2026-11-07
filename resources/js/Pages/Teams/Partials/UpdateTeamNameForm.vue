<script setup>
import { useForm } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    team: Object,
    permissions: Object,
});

const form = useForm({
    name: props.team.name,
});

const updateTeamName = () => {
    form.put(route('teams.update', props.team), {
        errorBag: 'updateTeamName',
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="updateTeamName">
        <template #title>
            Team name
        </template>

        <template #description>
            The name people see in the team switcher, and who owns the team.
        </template>

        <template #form>
            <div>
                <InputLabel value="Team owner" />

                <div class="ac-row-main" style="margin-top: 8px;">
                    <span class="ac-avatar" aria-hidden="true">{{ (team.owner.name || '?').charAt(0).toUpperCase() }}</span>

                    <div>
                        <div class="ac-row-t">{{ team.owner.name }}</div>
                        <div class="ac-row-s">{{ team.owner.email }}</div>
                    </div>
                </div>
            </div>

            <div>
                <InputLabel for="name" value="Team name" />

                <TextInput
                    id="name"
                    v-model="form.name"
                    type="text"
                    name="name"
                    autocomplete="off"
                    :disabled="! permissions.canUpdateTeam"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                />

                <InputError :message="form.errors.name" />
            </div>
        </template>

        <template v-if="permissions.canUpdateTeam" #actions>
            <ActionMessage :on="form.recentlySuccessful">
                Saved.
            </ActionMessage>

            <PrimaryButton :disabled="form.processing">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
