document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('crop-modal');
    const uploadInput = document.getElementById('avatar-upload');
    const closeModal = document.getElementById('close-modal');
    const cancelCrop = document.getElementById('cancel-crop');
    const saveCrop = document.getElementById('save-crop');
    const avatarForm = document.getElementById('avatar-form');

    let croppie;

    if (!modal || !uploadInput) return;

    uploadInput.addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            const file = e.target.files[0];

            if (file.size > 5 * 1024 * 1024) {
                showAlert('warning', 'Imagen muy grande', 'La imagen no debe superar los 5MB');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                croppie = new Croppie(document.getElementById('crop-image-container'), {
                    viewport: { width: 300, height: 300, type: 'circle' },
                    boundary: { width: 400, height: 400 },
                    enableZoom: true,
                    showZoomer: true,
                    mouseWheelZoom: true,
                });

                croppie.bind({ url: event.target.result });
                modal.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    });

    function closeCropModal() {
        modal.classList.add('hidden');
        if (croppie) {
            croppie.destroy();
            croppie = null;
        }
        uploadInput.value = '';
    }

    if (closeModal) closeModal.addEventListener('click', closeCropModal);
    if (cancelCrop) cancelCrop.addEventListener('click', closeCropModal);

    if (saveCrop) {
        saveCrop.addEventListener('click', function() {
            if (!croppie) return;

            croppie.result({
                type: 'base64',
                size: { width: 400, height: 400 },
                format: 'webp',
                quality: 0.9
            }).then(function(base64) {
                fetch(base64)
                    .then(res => res.blob())
                    .then(blob => {
                        const file = new File([blob], 'avatar.webp', {
                            type: 'image/webp',
                            lastModified: Date.now()
                        });

                        const formData = new FormData();
                        const csrfToken = document.querySelector('meta[name="csrf-token"]');
                        if (csrfToken) formData.append('_token', csrfToken.content);
                        formData.append('_method', 'PATCH');
                        formData.append('avatar', file);

                        closeCropModal();
                        const avatarPreview = document.getElementById('avatar-preview');
                        if (avatarPreview) avatarPreview.src = base64;

                        if (avatarForm) {
                            fetch(avatarForm.action, {
                                method: 'POST',
                                body: formData,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(response => {
                                if (response.ok) {
                                    showAlert('success', '¡Éxito!', 'Foto de perfil actualizada correctamente');
                                } else {
                                    showAlert('error', 'Error', 'Error al guardar la imagen en el servidor');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showAlert('error', 'Error de red', 'No se pudo conectar con el servidor');
                            });
                        }
                    });
            });
        });
    }
});