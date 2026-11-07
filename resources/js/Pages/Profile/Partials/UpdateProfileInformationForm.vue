<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    user: Object,
});

const form = useForm({
    _method: 'PUT',
    name: props.user.name,
    email: props.user.email,
    photo: null,
});

const verificationLinkSent = ref(null);
const photoPreview = ref(null);
const photoInput = ref(null);

const updateProfileInformation = () => {
    if (photoInput.value) {
        form.photo = photoInput.value.files[0];
    }

    form.post(route('user-profile-information.update'), {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
        onSuccess: () => clearPhotoFileInput(),
    });
};

const sendEmailVerification = () => {
    verificationLinkSent.value = true;
};

const selectNewPhoto = () => {
    photoInput.value.click();
};

const updatePhotoPreview = () => {
    const photo = photoInput.value.files[0];

    if (! photo) return;

    const reader = new FileReader();

    reader.onload = (e) => {
        photoPreview.value = e.target.result;
    };

    reader.readAsDataURL(photo);
};

const deletePhoto = () => {
    router.delete(route('current-user-photo.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            photoPreview.value = null;
            clearPhotoFileInput();
        },
    });
};

const clearPhotoFileInput = () => {
    if (photoInput.value?.value) {
        photoInput.value.value = null;
    }
};
</script>

<template>
    <FormSection @submitted="updateProfileInformation">
        <template #title>
            Profile information
        </template>

        <template #description>
            Your name and the email address Dot.Press uses for your account.
        </template>

        <template #form>
            <!-- Profile Photo -->
            <div v-if="$page.props.jetstream.managesProfilePhotos">
                <input
                    id="photo"
                    ref="photoInput"
                    type="file"
                    class="hidden"
                    @change="updatePhotoPreview"
                >

                <InputLabel for="photo" value="Photo" />

                <div v-show="! photoPreview" class="mt-2">
                    <img :src="user.profile_photo_url" :alt="user.name" class="rounded-full size-20 object-cover">
                </div>

                <div v-show="photoPreview" class="mt-2">
                    <span
                        class="block rounded-full size-20 bg-cover bg-no-repeat bg-center"
                        :style="'background-image: url(\'' + photoPreview + '\');'"
                    />
                </div>

                <SecondaryButton class="mt-2 me-2" type="button" @click.prevent="selectNewPhoto">
                    Select a new photo
                </SecondaryButton>

                <SecondaryButton
                    v-if="user.profile_photo_path"
                    type="button"
                    class="mt-2"
                    @click.prevent="deletePhoto"
                >
                    Remove photo
                </SecondaryButton>

                <InputError :message="form.errors.photo" />
            </div>

            <div>
                <InputLabel for="name" value="Name" />
                <TextInput
                    id="name"
                    v-model="form.name"
                    type="text"
                    name="name"
                    required
                    autocomplete="name"
                    :aria-invalid="form.errors.name ? 'true' : undefined"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    required
                    autocomplete="username"
                    inputmode="email"
                    :aria-invalid="form.errors.email ? 'true' : undefined"
                />
                <InputError :message="form.errors.email" />

                <div v-if="$page.props.jetstream.hasEmailVerification && user.email_verified_at === null">
                    <p class="pr-hint">
                        This email address is not verified.

                        <Link
                            :href="route('verification.send')"
                            method="post"
                            as="button"
                            class="pr-textlink"
                            @click.prevent="sendEmailVerification"
                        >
                            Send the verification email again
                        </Link>
                    </p>

                    <p v-show="verificationLinkSent" class="ac-msg" role="status">
                        A new verification link is on its way.
                    </p>
                </div>
            </div>
        </template>

        <template #actions>
            <ActionMessage :on="form.recentlySuccessful">
                Saved.
            </ActionMessage>

            <PrimaryButton :disabled="form.processing">
                Save
            </PrimaryButton>
        </template>
    </FormSection>
</template>
