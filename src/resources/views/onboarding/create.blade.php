<x-guest-layout>
    <div class="mx-auto max-w-2xl rounded-xl bg-white p-8 shadow-lg dark:bg-gray-800" x-data="onboardingWizard()">
        <div class="mb-8 text-center">
            <p class="text-sm font-medium text-primary-600">Paso <span x-text="step"></span> de 4</p>
            <h1 class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">Configura tu cuenta</h1>
        </div>

        <form method="post" action="{{ route('onboarding.store') }}" enctype="multipart/form-data" novalidate @submit="prepareSubmit">
            @csrf
            <input type="hidden" name="avatar_base64" x-ref="avatarBase64">

            <section x-show="step === 1" class="space-y-6">
                <header class="mb-6 text-center">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Foto de Perfil</h2>
                    <p class="mt-2 text-gray-600 dark:text-gray-300">Haz clic en el icono de la cámara para cambiar tu foto.</p>
                </header>
                <div class="flex justify-center">
                    <div class="relative group">
                        <img id="onboarding-avatar-preview" src="{{ $user->avatar ? asset('storage/'.$user->avatar) : 'https://ui-avatars.com/api/?name='.urlencode($user->name).'&background=0a0a5e&color=fff&size=256' }}"
                            class="h-32 w-32 rounded-full object-cover border-4 border-gray-200 dark:border-gray-600 shadow-lg">
                        <label for="onboarding-avatar-upload" class="absolute bottom-0 right-0 cursor-pointer rounded-full border-2 border-white bg-primary-600 p-2 text-white shadow-md dark:border-gray-800">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <input id="onboarding-avatar-upload" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/webp" class="hidden">
                        </label>
                    </div>
                </div>
                <p class="text-center text-xs text-gray-500">Tu imagen se recortará en formato circular, igual que en Perfil.</p>
                <x-floating-input id="name" name="name" label="Nombre" maxlength="255" :value="old('name', $draft['name'] ?? $user->name)" required />
                <div>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">Correo electrónico</span>
                    <p class="mt-1 rounded-lg border border-gray-200 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-600 dark:text-gray-200">{{ $user->email }}</p>
                    <p class="mt-1 text-xs text-gray-500">El correo verificado no puede modificarse desde el wizard.</p>
                </div>
                <div class="flex justify-end"><x-primary-button type="button" @click="nextStep(2)">Siguiente</x-primary-button></div>
            </section>

            <section x-show="step === 2" class="space-y-6">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Configuración de tu cuenta</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Estos datos determinan cómo se muestran tus valores y cuándo termina cada ciclo financiero.</p>
                <x-floating-select id="moneda_preferida" name="moneda_preferida" label="Moneda preferida" :options="$monedas" :value="old('moneda_preferida', $draft['moneda_preferida'] ?? $user->moneda_preferida)" required />
                <p class="text-xs text-gray-500">Define el símbolo que verás en saldos, movimientos y compromisos.</p>
                <x-floating-input id="fecha_corte_dia" name="fecha_corte_dia" label="Día de corte mensual" type="number" min="1" max="31" :value="old('fecha_corte_dia', $draft['fecha_corte_dia'] ?? ($user->fecha_corte_dia ?: 31))" required />
                <p class="text-xs text-gray-500">Día en que termina tu ciclo financiero mensual.</p>
                <x-floating-select id="zona_horaria" name="zona_horaria" label="Zona horaria" :options="['America/El_Salvador'=>'El Salvador (GMT-6)','America/Guatemala'=>'Guatemala (GMT-6)','America/Mexico_City'=>'Ciudad de México (GMT-6)','UTC'=>'UTC','America/New_York'=>'Nueva York (GMT-5/-4)','Europe/Madrid'=>'Madrid (GMT+1/+2)']" :value="old('zona_horaria', $draft['zona_horaria'] ?? ($user->zona_horaria ?: 'America/El_Salvador'))" required />
                <p class="text-xs text-gray-500">Se utiliza para calcular fechas y cierres según tu ubicación.</p>
                <div class="flex justify-between"><x-secondary-button type="button" @click="backStep(1)">Atrás</x-secondary-button><x-primary-button type="button" @click="nextStep(3)">Siguiente</x-primary-button></div>
            </section>

            <section x-show="step === 3" class="space-y-6">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Configuración financiera</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Indica el efectivo disponible que tienes en este momento para iniciar correctamente los cálculos del sistema.</p>
                <input type="hidden" name="categoria_color" value="#0a0a5e">
                <x-floating-money id="efectivo_inicial" label="Efectivo disponible" value="{{ $draft['efectivo_inicial'] ?? '0.00' }}" :symbol="$simbolo" required />
                <div class="flex justify-between"><x-secondary-button type="button" @click="backStep(2)">Atrás</x-secondary-button><x-primary-button type="button" @click="nextStep(4)">Siguiente</x-primary-button></div>
            </section>

            <section x-show="step === 4" class="space-y-6">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Compromisos iniciales</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Puedes registrar ninguno, uno o varios compromisos que ya sabes que tendrás que pagar.</p>
                <template x-for="(item, index) in compromisos" :key="item.key">
                    <div class="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <x-floating-input id="compromiso_descripcion" label="Descripción" maxlength="255" x-bind:name="`compromisos[${index}][descripcion]`" x-model="item.descripcion" />
                        <x-floating-select id="compromiso_tipo" label="Tipo" :options="['unica'=>'Una sola vez','recurrente'=>'Recurrente']" x-bind:name="`compromisos[${index}][recurrencia_tipo]`" x-model="item.recurrencia_tipo" />
                        <div x-show="item.recurrencia_tipo === 'unica'">
                            <x-floating-date id="compromiso_fecha_esperada" label="Fecha esperada" :min="$dateMin" :max="$dateMax" x-bind:name="`compromisos[${index}][fecha_esperada]`" x-model="item.fecha_esperada" required />
                        </div>
                        <div x-show="item.recurrencia_tipo === 'recurrente'" class="space-y-3">
                            <x-floating-select id="compromiso_frecuencia" label="Frecuencia" :options="['semanal'=>'Semanal','quincenal'=>'Quincenal','mensual'=>'Mensual']" x-bind:name="`compromisos[${index}][frecuencia]`" x-model="item.frecuencia" />
                            <div x-show="item.frecuencia === 'semanal'">
                                <x-floating-select id="compromiso_dia_semana" label="Día de la semana" :options="[0=>'Domingo',1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado']" x-bind:name="`compromisos[${index}][dia_semana]`" x-model="item.dia_semana" />
                            </div>
                            <div x-show="item.frecuencia === 'quincenal'" class="grid grid-cols-2 gap-3">
                                <x-floating-input id="compromiso_dia_pago" label="Primer día (1-31)" type="number" min="1" max="31" x-bind:name="`compromisos[${index}][dia_pago]`" x-model="item.dia_pago" x-on:input="item.dia_secundario = Math.min(31, Number(item.dia_pago || 1) + 15)" />
                                <div>
                                    <span class="block text-xs text-gray-500">Segundo día calculado</span>
                                    <p class="mt-1 rounded-lg border border-gray-300 px-4 py-2.5 text-sm text-gray-700 dark:border-gray-600 dark:text-gray-200" x-text="item.dia_secundario"></p>
                                    <input type="hidden" x-bind:name="`compromisos[${index}][dia_secundario]`" x-model="item.dia_secundario">
                                </div>
                            </div>
                            <div x-show="item.frecuencia === 'mensual'">
                                <x-floating-input id="compromiso_dia_pago_mensual" label="Día del mes (1-31)" type="number" min="1" max="31" x-bind:name="`compromisos[${index}][dia_pago]`" x-model="item.dia_pago" />
                            </div>
                            <x-floating-select id="compromiso_finalizacion" label="Finalización" :options="['indefinido'=>'Indefinido','cuotas'=>'Número de cuotas']" x-bind:name="`compromisos[${index}][finalizacion]`" x-model="item.finalizacion" />
                            <div x-show="item.finalizacion === 'cuotas'">
                                <x-floating-input id="compromiso_cuotas" label="Cantidad de cuotas" type="number" min="1" x-bind:name="`compromisos[${index}][cuotas]`" x-model="item.cuotas" />
                            </div>
                        </div>
                        <x-floating-money id="compromiso_monto" label="Monto" value="0.00" :symbol="$simbolo" x-bind:name="`compromisos[${index}][monto]`" x-model="item.monto" />
                        <x-floating-select id="compromiso_medio_pago" label="Medio de pago" :options="['por_definir'=>'Por definir','efectivo'=>'Efectivo','tarjeta'=>'Tarjeta','transferencia'=>'Transferencia']" x-bind:name="`compromisos[${index}][medio_pago]`" x-model="item.medio_pago" />
                        <button type="button" class="text-sm text-red-600" @click="removeCommitment(index)">Quitar compromiso</button>
                    </div>
                </template>
                <x-secondary-button type="button" @click="addCommitment()">Agregar compromiso</x-secondary-button>
                <div class="flex justify-between"><x-secondary-button type="button" @click="step = 3">Atrás</x-secondary-button><x-primary-button type="submit">Finalizar configuración</x-primary-button></div>
            </section>
        </form>
    </div>

    <x-modal name="onboarding-crop-modal">
        <div class="p-6">
            <div id="onboarding-crop-image-container" class="mb-6"></div>
            <div class="flex justify-end gap-3"><x-secondary-button type="button" @click="$dispatch('close-modal', 'onboarding-crop-modal')">Cancelar</x-secondary-button><x-primary-button type="button" id="onboarding-save-crop">Guardar y Recortar</x-primary-button></div>
        </div>
    </x-modal>

    <script>
        function onboardingWizard() {
            return {
                step: 1,
                compromisos: [],
                addCommitment() { this.compromisos.push({ key: Date.now() + Math.random(), descripcion: '', monto: '0.00', fecha_esperada: '', medio_pago: 'por_definir', recurrencia_tipo: 'unica', frecuencia: 'mensual', dia_pago: 1, dia_semana: 1, dia_secundario: 16, finalizacion: 'indefinido', cuotas: '' }); },
                removeCommitment(index) { this.compromisos.splice(index, 1); },
                async nextStep(target) {
                    const formData = new FormData(this.$root.querySelector('form'));
                    formData.set('step', this.step);
                    formData.set('compromisos', JSON.stringify(this.compromisos));
                    await fetch('{{ route('onboarding.progress') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' }, body: formData });
                    this.step = target;
                },
                async backStep(target) {
                    const response = await fetch('{{ route('onboarding.progress.data') }}', { headers: { Accept: 'application/json' } });
                    if (response.ok) {
                        const draft = (await response.json()).data || {};
                        if (Array.isArray(draft.compromisos)) this.compromisos = draft.compromisos;
                    }
                    this.step = target;
                },
                prepareSubmit() {
                    this.$root.querySelectorAll('[data-money-input]').forEach(input => formatMoneyInput(input));
                    this.$root.querySelectorAll('[data-date-input]').forEach(input => { if (input.value) validateDateInput(input); });
                }
            };
        }
        document.addEventListener('DOMContentLoaded', () => {
            const upload = document.getElementById('onboarding-avatar-upload');
            const save = document.getElementById('onboarding-save-crop');
            let crop;
            upload?.addEventListener('change', event => {
                const file = event.target.files?.[0];
                if (!file || !file.type.startsWith('image/')) return;
                const reader = new FileReader();
                reader.onload = () => {
                    crop = new Croppie(document.getElementById('onboarding-crop-image-container'), { viewport: { width: 300, height: 300, type: 'circle' }, boundary: { width: 400, height: 400 }, enableZoom: true, showZoomer: true, mouseWheelZoom: true });
                    crop.bind({ url: reader.result });
                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'onboarding-crop-modal' }));
                };
                reader.readAsDataURL(file);
            });
            save?.addEventListener('click', () => crop?.result({ type: 'base64', size: { width: 400, height: 400 }, format: 'webp', quality: .9 }).then(base64 => {
                document.getElementById('onboarding-avatar-preview').src = base64;
                document.querySelector('[x-ref="avatarBase64"]').value = base64;
                window.dispatchEvent(new CustomEvent('close-modal', { detail: 'onboarding-crop-modal' }));
                crop.destroy(); crop = null;
            }));
        });
    </script>
</x-guest-layout>
