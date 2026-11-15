{{-- Aviso de un gasto que pasa su presupuesto: cubrir lo que se pasa con otras categorías (ver cubrirPresupuesto en app.js) --}}
<div x-data="cubrirPresupuesto" x-show="abierto" x-cloak class="fixed inset-0 z-[70] overflow-y-auto" @keydown.escape.window="abierto && cerrar(null)">
    <div class="fixed inset-0 bg-gray-900/60" @click="cerrar(null)"></div>
    <div class="relative mx-auto my-8 w-[calc(100%-2rem)] max-w-lg rounded-2xl bg-white shadow-xl dark:bg-gray-800" x-show="abierto" x-transition>
        <template x-if="detalle">
            <div>
                <div class="flex items-start gap-3 border-b border-gray-200 px-6 py-5 dark:border-gray-700">
                    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                    </span>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100" x-text="modo === 'gasto' ? (detalle.sin_presupuesto ? 'Esta categoría no tenía presupuesto' : 'Este gasto pasa tu presupuesto') : (repartir ? 'Pasar presupuesto a otras categorías' : 'Este presupuesto se pasó')"></h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            <template x-if="modo === 'gasto' && detalle.sin_presupuesto"><span>«<span class="font-medium" x-text="detalle.categoria"></span>» no tiene presupuesto en este ciclo<span x-show="centavos(detalle.queda) < 0"> y ya lleva <span x-text="dinero(-centavos(detalle.queda))"></span> gastados</span>. Si lo guardas, te pasas del tope por <span class="font-semibold text-red-600 dark:text-red-400" x-text="dinero(excedente)"></span>. ¿Aun así deseas guardarlo?</span></template>
                            <template x-if="modo === 'gasto' && !detalle.sin_presupuesto"><span>A «<span class="font-medium" x-text="detalle.categoria"></span>» le quedan <span x-text="dinero(centavos(detalle.queda))"></span> y el gasto es de <span x-text="dinero(centavos(detalle.gasto))"></span>: te pasarías por <span class="font-semibold text-red-600 dark:text-red-400" x-text="dinero(excedente)"></span>.</span></template>
                            <template x-if="repartir"><span>A «<span class="font-medium" x-text="detalle.categoria"></span>» le quedan <span class="font-semibold text-green-600 dark:text-green-400" x-text="dinero(centavos(detalle.disponible))"></span>. Pásalo a las categorías que sí lo van a usar.</span></template>
                            <template x-if="modo === 'manual'"><span>«<span class="font-medium" x-text="detalle.categoria"></span>» se pasó por <span class="font-semibold text-red-600 dark:text-red-400" x-text="dinero(excedente)"></span>.</span></template>
                        </p>
                    </div>
                </div>

                <div class="px-6 py-5">
                    <template x-if="filas.length">
                        <div>
                            <p class="text-sm text-gray-700 dark:text-gray-300" x-show="repartir">¿A cuáles? Elige una o varias. Se sugiere repartir todo lo que le queda, pero puedes pasar menos: cambia cuánto recibe cada una.</p>
                            <p class="text-sm text-gray-700 dark:text-gray-300" x-show="!repartir">¿Quieres cubrirlo con lo que les queda a otras categorías? Elige una o varias y, si quieres, cambia cuánto le quitas a cada una.</p>
                            <ul class="mt-4 space-y-2">
                                <template x-for="f in filas" :key="f.presupuesto_id">
                                    <li class="flex items-center gap-3 rounded-lg border px-3 py-2.5" :class="f.elegida ? 'border-blue-500 bg-blue-50/60 dark:border-blue-400 dark:bg-blue-900/20' : 'border-gray-200 dark:border-gray-700'">
                                        <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900" :id="'cubrir-' + f.presupuesto_id" x-model="f.elegida" @change="sugerir()">
                                        <label :for="'cubrir-' + f.presupuesto_id" class="min-w-0 flex-1 cursor-pointer">
                                            <span class="flex items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="'background:' + (f.color || '#9CA3AF')"></span><span class="truncate" x-text="f.categoria"></span></span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400"><span x-text="repartir ? 'Ahora le quedan' : 'Le quedan'"></span> <span :class="centavos(f.disponible) < 0 && 'text-red-600 dark:text-red-400'" x-text="(centavos(f.disponible) < 0 ? '−' : '') + dinero(Math.abs(centavos(f.disponible)))"></span></span>
                                        </label>
                                        <div class="w-28 shrink-0" x-show="f.elegida">
                                            <div class="relative">
                                                <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-sm text-gray-500">$</span>
                                                <input type="text" inputmode="decimal" x-model="f.monto" aria-label="Cuánto"
                                                       class="w-full rounded-md border-gray-300 py-1.5 pl-6 pr-2 text-right text-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                                                       :class="excede(f) && 'border-red-500 text-red-600'">
                                            </div>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                            <div class="mt-4 flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/50">
                                <span class="text-gray-600 dark:text-gray-400" x-text="repartir ? 'Repartes' : 'Cubres'"></span>
                                <span class="font-semibold" :class="valido ? 'text-green-600 dark:text-green-400' : 'text-gray-900 dark:text-gray-100'"><span x-text="dinero(suma)"></span> de <span x-text="dinero(excedente)"></span></span>
                            </div>
                            <p class="mt-2 text-sm text-amber-700 dark:text-amber-400" x-show="aviso" x-text="aviso"></p>
                        </div>
                    </template>
                    <p x-show="!filas.length" class="text-sm text-gray-600 dark:text-gray-300" x-text="repartir ? 'No hay otras categorías con presupuesto este ciclo.' : 'Ninguna otra categoría tiene presupuesto disponible este ciclo.'"></p>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-gray-200 px-6 py-4 sm:flex-row sm:justify-end dark:border-gray-700">
                    <button type="button" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" @click="cerrar(null)">Cancelar</button>
                    <button type="button" x-show="modo === 'gasto'" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" @click="cerrar({ modo: 'pasarme' })" x-text="detalle.sin_presupuesto ? 'Guardarlo sin presupuesto' : 'Guardar y pasarme'"></button>
                    <button type="button" x-show="filas.length" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!valido" @click="cubrir()" x-text="modo === 'gasto' ? 'Cubrir y guardar' : (repartir ? 'Pasar' : 'Cubrir')"></button>
                </div>
            </div>
        </template>
    </div>
</div>
