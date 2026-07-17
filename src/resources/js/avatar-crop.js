// Esperar a que el DOM esté cargado
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('crop-modal');
    const uploadInput = document.getElementById('avatar-upload');
    const closeModal = document.getElementById('close-modal');
    const cancelCrop = document.getElementById('cancel-crop');
    const saveCrop = document.getElementById('save-crop');
    const avatarForm = document.getElementById('avatar-form');

    let croppie;

    // Si no existen los elementos principales, no hacer nada
    if (!modal || !uploadInput) {
        return;
    }

    // Cuando se selecciona una imagen
    uploadInput.addEventListener('change', function(e) {
        if (e.target.files && e.target.files[0]) {
            const file = e.target.files[0];

            // Validar tamaño (5MB)
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Imagen muy grande',
                    text: 'La imagen no debe superar los 5MB',
                    confirmButtonColor: '#0a0a5e'
                });
                return;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                // Inicializar Croppie
                croppie = new Croppie(document.getElementById('crop-image-container'), {
                    viewport: {
                        width: 300,
                        height: 300,
                        type: 'circle'
                    },
                    boundary: {
                        width: 400,
                        height: 400
                    },
                    enableZoom: true,
                    showZoomer: true,
                    mouseWheelZoom: true,
                });

                croppie.bind({
                    url: event.target.result
                });

                // Mostrar modal
                modal.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        }
    });

    // Cerrar modal
    function closeCropModal() {
        modal.classList.add('hidden');
        if (croppie) {
            croppie.destroy();
            croppie = null;
        }
        uploadInput.value = '';
    }

    if (closeModal) {
        closeModal.addEventListener('click', closeCropModal);
    }
    
    if (cancelCrop) {
        cancelCrop.addEventListener('click', closeCropModal);
    }

    if (saveCrop) {
        saveCrop.addEventListener('click', function() {
            if (!croppie) return;

            croppie.result({
                type: 'base64',
                size: {
                    width: 400,
                    height: 400
                },
                format: 'webp',
                quality: 0.9
            }).then(function(base64) {
                // Convertir base64 a blob
                fetch(base64)
                    .then(res => res.blob())
                    .then(blob => {
                        // Crear archivo desde blob
                        const file = new File([blob], 'avatar.webp', {
                            type: 'image/webp',
                            lastModified: Date.now()
                        });

                        // Crear FormData
                        const formData = new FormData();
                        const csrfToken = document.querySelector('meta[name="csrf-token"]');
                        if (csrfToken) {
                            formData.append('_token', csrfToken.content);
                        }
                        formData.append('_method', 'PATCH');
                        formData.append('avatar', file);

                        // Cerrar modal
                        closeCropModal();

                        // Actualizar preview
                        const avatarPreview = document.getElementById('avatar-preview');
                        if (avatarPreview) {
                            avatarPreview.src = base64;
                        }

                        // Enviar con fetch
                        if (avatarForm) {
                            fetch(avatarForm.action, {
                                method: 'POST',
                                body: formData,
                                headers: {
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            })
                            .then(response => {
                                if (response.ok) {
                                    // Mostrar SweetAlert de éxito
                                    Swal.fire({
                                        icon: 'success',
                                        title: '¡Excelente!',
                                        text: 'Foto de perfil actualizada correctamente',
                                        timer: 2000,
                                        showConfirmButton: false,
                                        position: 'top-end',
                                        toast: true
                                    });
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: 'Error al guardar la imagen en el servidor',
                                        confirmButtonColor: '#0a0a5e'
                                    });
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error de red',
                                    text: 'No se pudo conectar con el servidor',
                                    confirmButtonColor: '#0a0a5e'
                                });
                            });
                        }
                    });
            });
        });
    }
});