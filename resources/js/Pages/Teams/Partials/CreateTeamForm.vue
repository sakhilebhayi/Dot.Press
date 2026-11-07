<script setup>
import { useForm } from '@inertiajs/vue3';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const form = useForm({
    name: '',
});

const createTeam = () => {
    form.post(route('teams.store'), {
        errorBag: 'createTeam',
        preserveScroll: true,
    });
};
</script>

<template>
    <FormSection @submitted="createTeam">
        <template #title>
            Team details
        </template>

        <template #description>
            A team shares decks and one set of roles between the people in it.
        </template>

        <template #form>
            <div>
                <InputLabel value="Team owner" />

                <div class="ac-row-main" style="margin-top: 8px;">
                    <span class="ac-avatar" aria-hidden="true">{{ ($page.props.auth.user.name || '?').charAt(0).toUpperCase() }}</span>

                    <div>
                        <div class="ac-row-t">{{ $page.props.auth.user.name }}</div>
                        <div class="ac-row-s">{{ $page.props.auth.user.email }}</div>
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
                    autofocus
                    autocomplete="off"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                />
                <InputError :message="form.errors.name" />
            </div>
        </template>

        <template #actions>
            <PrimaryButton :disabled="form.processing">
                Create team
            </PrimaryButton>
        </template>
    </FormSection>
</template>
