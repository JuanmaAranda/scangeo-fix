=== scanGEO Fixer ===
Contributors: scangeo
Tags: seo, geo, ai, schema, audit
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.4
License: GPLv2 or later

Sube el informe .md de scanGEO.app, mira tu nota GEO y repara automáticamente (o con propuesta revisable) los fallos SEO/GEO detectados.

== Description ==

scanGEO Fixer lee el informe Markdown exportado por scanGEO.app (con su bloque JSON de automatización), muestra tu puntuación por categoría, lista todos los fallos en pantalla e intenta repararlos uno a uno, marcando cada resultado:

* ✔ Corregido automáticamente (con botón para deshacer)
* 📝 Propuesta de IA lista para revisar y aplicar (o editar antes de aplicar)
* ✖ No se pudo arreglar (con el motivo)
* ✋ Requiere acción manual (con guía)

= Qué enseña =

* Puntuación por categoría (técnico, contenido, GEO, off-page) del informe cargado.
* Evolución de la puntuación general entre informes subidos en el tiempo.

= Qué repara automáticamente =

* Atributos alt de imágenes (contenido + biblioteca de medios).
* Canonical, meta viewport y atributo lang.
* Open Graph (og:title, description, image, type, url, site_name).
* JSON-LD Organization + WebSite (portada) y Article con autor y fechas (entradas).
* robots.txt virtual: acceso explícito a crawlers de IA (GPTBot, ClaudeBot, PerplexityBot...) y directiva Sitemap.
* Sitemap nativo de WordPress y /llms.txt dinámico.

= Qué propone para revisar antes de aplicar (requiere IA) =

* Meta descriptions y títulos SEO — compatible con Yoast, Rank Math y SEOPress, o con salida propia si no hay plugin SEO.
* Bloques de preguntas frecuentes (FAQ).
* Párrafo de respuesta directa al principio del contenido.
* Sugerencias de enlaces internos.
* Ampliación de contenido corto (párrafos nuevos con información relevante).

Todas las propuestas de IA se muestran en un cuadro de texto editable: nada se guarda en tu web hasta que pulses "Aplicar". Todo lo que sí se aplica automáticamente puede deshacerse con un clic.

= Qué NO toca =

Los headings, la estructura semántica y la longitud del contenido nunca se reescriben automáticamente: se marcan como acción manual con instrucciones, porque cambiarlos sin supervisión podría alterar el significado del texto. Tampoco toca rendimiento (caché) ni SSL.

== Installation ==

1. Sube la carpeta `scangeo-fix` a `/wp-content/plugins/` o instala el .zip desde Plugins → Añadir nuevo.
2. Activa el plugin.
3. (Opcional) En scanGEO Fixer → Ajustes, añade tu clave API de Anthropic u OpenAI para generar textos con IA, y tus perfiles sociales para el schema.
4. Ve a scanGEO Fixer, sube el .md exportado por scanGEO.app y pulsa "Reparar todo".

== Changelog ==

= 2.2.4 =
* Corrección definitiva del aviso de actualización que no llegaba a Escritorio > Actualizaciones: check_update() calculaba la carpeta del plugin instalado comprobando solo scangeo-fix/ o scangeo-fixer/ (las rutas "canónicas"), nunca la carpeta real cuando esta incluye el número de versión en el nombre (scangeo-fix-2.2.0, herencia de instalaciones manuales anteriores). WordPress descarta en silencio cualquier aviso de actualización cuya carpeta no coincida exactamente con un plugin instalado de verdad, así que nunca aparecía en la lista aunque el propio panel de scanGEO Fixer sí detectara bien la versión nueva. Ahora usa el mismo resolutor de carpeta ya usado en el resto del plugin.

= 2.2.3 =
* Corrección importante: aunque la comprobación de versión funcionaba bien (el aviso ya salía dentro del propio panel de scanGEO Fixer), la entrada nunca llegaba a Escritorio > Actualizaciones. Causa: otro plugin instalado en el sitio que gestiona actualizaciones automáticas también engancha el mismo filtro de WordPress y puede reconstruir la lista de plugins con actualización disponible, quedándose solo con los que él reconoce. Ahora scanGEO Fixer se engancha con la prioridad más alta posible para ejecutarse el último y asegurarse de que su entrada no se pierda.

= 2.2.2 =
* Corrección importante: la propia comprobación de actualizaciones (la llamada a la API de GitHub) usaba un timeout fijo de 10s sin protección, así que en hostings que limitan las conexiones salientes a ese tiempo, la comprobación fallaba en silencio: no aparecía ni el aviso de nueva versión ni ningún error, simplemente el plugin no salía en la pantalla de actualizaciones. Ahora usa el mismo blindaje de timeout y reintento que ya tenían las llamadas a IA, y si aun así falla, se muestra un aviso explicando el motivo junto al número de versión.

= 2.2.1 =
* Corrección importante: el ZIP de la release se empaquetaba con rutas de Windows (barra invertida) y sin una carpeta raíz real, así que WordPress no podía identificar una única carpeta al extraerlo y cada actualización acababa en un directorio nuevo (scangeo-fix-2.1.9, scangeo-fix-2.2.0...). El paquete ahora usa rutas estándar (barra normal) con una sola carpeta `scangeo-fix/`, para que las actualizaciones futuras se instalen siempre en el mismo sitio.
* Corrección importante: varios reparadores que afectan a más de una página (alt de imágenes, meta descriptions, títulos SEO, FAQ, respuesta directa, enlaces internos, ampliar contenido) marcaban la fila entera como "corregido" en cuanto se arreglaba una sola página, aunque el resto fallaran. Ahora existe un estado "corregido en parte" honesto que dice cuántas páginas quedaron pendientes, y el botón "Reparar" se queda activo para reintentar solo esas.

= 2.2.0 =
* Unificado el identificador del paquete: ZIP y carpeta raíz pasan a llamarse siempre `scangeo-fix`.
* Las instalaciones antiguas `scangeo-fixer` se migran automáticamente durante la actualización.
* Las propuestas aplicadas quedan marcadas como corregidas y ya no vuelven a ofrecerse para la misma página.

= 2.1.9 =
* Corregida la codificación de todos los textos del panel y de las propuestas: tildes, eñes y signos vuelven a mostrarse correctamente.

= 2.1.8 =
* Corregido el enlace de actualización: registra primero la versión en el listado nativo de WordPress y después abre Escritorio > Actualizaciones.
* El paquete incluye siempre la carpeta raíz `scangeo-fix/`, necesaria para que WordPress lo reconozca como una actualización.

= 2.1.6 =
* Corrección crítica del actualizador: el ZIP vuelve a incluir una única carpeta raíz `scangeo-fixer/`. WordPress ya instala la actualización en la ruta esperada y no desactiva el plugin por archivo inexistente.

= 2.1.5 =
* Las propuestas de FAQ, respuesta directa y ampliación de contenido se solicitan desde el navegador del administrador a scanGEO.app. Ya no dependen de que el servidor WordPress pueda abrir una conexión saliente.
* Las propuestas se muestran como borrador editable y solo se guardan tras confirmarlas con «Aplicar esta». «Reparar todo» no solicita propuestas editoriales automáticamente.

= 2.1.2 =
* Corrección crítica: al cargar un informe, el callback que ajustaba el tiempo de espera de las conexiones de IA no era invocable por WordPress y podía provocar un error fatal. Ahora es público y fuerza correctamente 45 segundos.
* Corrección: aunque la generación opcional del resumen de IA falle, el informe ya cargado se conserva y el panel muestra un aviso recuperable en vez de interrumpir el sitio.
* Corrección: «Deshacer» restaura también los atributos alt modificados en la biblioteca de medios y elimina los metadatos que no existían antes de aplicar una propuesta.
* Corrección de distribución: el ZIP usa rutas estándar con `/`, compatibles con servidores Linux al instalar el plugin.

= 2.1.1 =
* Corrección importante: las llamadas al proveedor de IA (incluida, Anthropic u OpenAI) ahora fuerzan su propio tiempo de espera (45 s) por encima del que el hosting pudiera estar imponiendo mediante el filtro interno de WordPress `http_request_timeout`. Muchos hostings compartidos limitan ahí, de forma fija, todas las peticiones salientes de cualquier plugin a unos 10 segundos, sin importar lo que el plugin pida — esto es lo que producía sistemáticamente el error "cURL error 28: Connection timed out after 10002 milliseconds" en el botón Reparar, incluso después del reintento automático.
* Corrección: se completa la lógica de reintento automático y el método de diagnóstico usado por el botón "Comprobar conexión" de Ajustes, que en la versión 2.1.0 habían quedado sin terminar (el botón existía pero llamaba a un método que no llegó a incluirse).

= 2.1.0 =
* Nuevo: botón "Comprobar conexión" en Ajustes → Generación de textos con IA, para diagnosticar de un vistazo si el servidor puede contactar con el proveedor de IA configurado.
* Aclaración importante: los fallos de tipo "cURL error 28: Connection timed out" no son un problema de permisos de escritura en WordPress, sino de conexión saliente del servidor hacia el proveedor de IA.

= 2.0.0 =
* Nuevo: IA incluida en el plugin por defecto (GPT-4o mini), sin necesidad de configurar ninguna clave. Con un límite de consultas gratis al mes por sitio; al agotarse, se avisa y se puede añadir una clave propia de Anthropic u OpenAI en Ajustes para seguir sin límite.
* Nuevo: indicador de cuota restante en Ajustes ("X de Y consultas gratis este mes").
* Nuevo: aviso explicativo en el informe si se agota la cuota, con enlace directo a Ajustes.

= 1.9.10 =
* Cambio de fondo: la comparación de versiones ya no usa la constante SCANGEO_FIXER_VERSION (definida al ejecutar el PHP, y potencialmente desincronizada de OPcache en algunos hostings justo después de actualizar), sino que lee la versión directamente de la cabecera del archivo en disco, igual que hace WordPress. Si el aviso fantasma seguía apareciendo tras cada actualización, esta era la explicación más probable.
* Nuevo: si alguna vez la versión en memoria y la del archivo no coinciden, aparece un pequeño aviso "⚠ caché de PHP desincronizada" junto a la versión, con el detalle en el tooltip — para poder diagnosticarlo de un vistazo si volviera a pasar.

= 1.9.9 =
* Corrección de la causa real del aviso fantasma persistente: al actualizar un único plugin con el enlace individual "Actualízalo ahora" (en vez de la actualización en bloque con checkboxes), WordPress identifica el plugin con una clave distinta ('plugin', no 'plugins'). El código de limpieza de caché solo reconocía la segunda, así que nunca se disparaba tras una actualización individual. Ahora reconoce ambas.

= 1.9.8 =
* Corrección de fondo del aviso fantasma: la API de GitHub tiene un límite de peticiones por hora; si un chequeo fallaba por eso (u otro fallo de red), el plugin dejaba el aviso de actualización anterior "colgado" en vez de retirarlo. Ahora, ante cualquier fallo de comprobación, se retira el aviso en vez de conservar uno posiblemente obsoleto, y se reintenta a los 15 minutos en vez de 6 horas.
* Corrección: la comprobación automática solo se disparaba al visitar la página del propio plugin; ahora también se dispara al visitar Plugins o Actualizaciones, que es donde más se suele ver el aviso.

= 1.9.7 =
* Corrección: aviso fantasma de "hay una nueva versión" que se quedaba mostrando la misma versión ya instalada. Ahora se limpia explícitamente la caché justo después de cada actualización, y se marca de forma explícita "no_update" cuando no la hay (WordPress dejaba de refrescarlo solo).
* Corrección: "Ver detalles" podía aparecer duplicado en la fila del plugin; se ha añadido protección para que nunca se registre/añada dos veces.
* Mejora: el modal de "Ver detalles" ahora tiene banner de cabecera con el logo, una descripción completa de lo que hace el plugin, instrucciones de instalación, y el registro de cambios de varias versiones (no solo la última) — igual que los plugins de wordpress.org, sin necesitar estar en su repositorio.

= 1.9.6 =
* Nuevo: en el listado de Plugins, la fila de scanGEO Fixer ahora tiene "Visitar la web del plugin" y el autor enlazan a scangeo.app, y aparece "Ver detalles" con el mismo modal que usan los plugins de wordpress.org (no hace falta estar en el repositorio oficial para tenerlo).

= 1.9.5 =
* Corrección importante: "Comprobar actualización del plugin" dejaba el panel en blanco porque el redirect se ejecutaba después de que WordPress ya hubiera empezado a enviar la página. Movido a una fase mucho más temprana (admin_init); ahora redirige correctamente.
* Corrección: se forzaba el recálculo completo de la comprobación de actualizaciones de WordPress (antes solo se avisaba a medias).
* Cambio: el aviso de "Nueva versión disponible" lleva ahora a Actualizaciones (update-core.php), donde se ve el aviso completo con nombre y detalles, en vez de a Plugins.
* Nuevo: icono del plugin (el isotipo de scanGEO) visible en la pantalla de Actualizaciones y en el listado de plugins.

= 1.9.4 =
* Corrección de texto: "Comprobar ahora" pasa a llamarse "Comprobar actualización del plugin", para no confundirlo con volver a escanear el sitio en scanGEO.app.

= 1.9.3 =
* Nuevo: enlace "Comprobar ahora" junto a la versión, que fuerza la comprobación de actualizaciones al momento (en vez de esperar al ciclo automático de WordPress). Además, cada visita al panel del plugin comprueba automáticamente si hay versión nueva (como máximo una vez cada 10 minutos), reflejándose también en el aviso estándar de Plugins → "Hay una nueva versión disponible".

= 1.9.2 =
* El comprobador de actualizaciones ya no depende de adjuntar el .zip a mano en un Release de GitHub: lo descarga directamente desde /dist dentro del propio repositorio. Publicar una versión nueva queda completamente automatizado.

= 1.9.1 =
* Configuración: el comprobador de actualizaciones apunta ya al repositorio real, github.com/JuanmaAranda/scangeo-fix.

= 1.9.0 =
* Cambio: "Volver a escanear sitio" pasa a ser un botón azul junto al de "Cargar informe" (antes era un enlace de texto). Si ya hay un informe cargado, el botón de subida pasa a decir "Cargar nuevo informe".
* Nuevo: número de versión visible junto al logo.
* Nuevo: comprobación automática de actualizaciones contra un repositorio de GitHub (ver includes/class-scangeo-updater.php — requiere configurar el repositorio una vez). Cuando hay una versión más reciente, aparece una pastilla junto al logo y la actualización se puede instalar desde Plugins como cualquier otro plugin.

= 1.8.0 =
* Nuevo: resumen en palabras sencillas con semáforo (rojo/ámbar/verde) generado por IA a partir del informe, usando la clave configurada en Ajustes.
* Nuevo: si no hay clave de IA configurada, aparece un aviso sobre las puntuaciones explicando que hace falta añadirla en Ajustes para aprovechar el plugin al completo.
* Nuevo: botón "Volver a escanear sitio" que lleva a scangeo.app.
* Nuevo: pestaña "Ayuda" con preguntas frecuentes desplegables sobre el uso del plugin.

= 1.7.0 =
* Corrección de UX: cuando un fallo solo admite solución manual (p. ej. ya gestionado por Yoast/Rank Math, o requiere hosting/edición humana), el botón "Reparar" desaparece tras el primer intento y se sustituye por "Solución manual", para que no parezca que el botón no hace nada.
* Nuevo: en Ajustes, enlaces directos para conseguir la clave API de Anthropic y de OpenAI.
* Nuevo: enlace discreto a scangeo.app junto al logo del panel.

= 1.6.1 =
* Corrección real del problema anterior: el filtro de URLs traducidas solo actuaba al cargar la página. Al aplicar o descartar una propuesta con los botones (que actualiza la fila sin recargar), la respuesta del servidor no pasaba por ese filtro y las propuestas antiguas en otro idioma podían reaparecer. Ahora se filtran también ahí, y se limpian de forma permanente de los datos guardados la primera vez que se detectan.

= 1.6.0 =
* Nuevo: cada fallo lleva ahora una explicación en lenguaje sencillo de qué comprueba esa regla y por qué importa, para quien no venga de un perfil técnico.
* Corrección: en el listado de páginas afectadas, las URLs traducidas (TranslatePress) se marcan como "traducción, no se modifica". Además, si una propuesta de IA ya generada antes de la versión 1.3.1 incluía alguna de estas URLs, ya no se ofrece aplicarla.

= 1.5.0 =
* Nuevo: pestañas para filtrar los fallos por categoría (Técnico, Contenido, GEO, Off-page), con el número de fallos de cada una. El filtrado es instantáneo, sin recargar la página.

= 1.4.0 =
* Rediseño visual completo del panel: tarjetas espaciadas en vez de tabla apretada, tipografía más grande, colores de marca (azul scanGEO) y mejores estados de color por fila (verde/rojo/ámbar). Los contadores de fallos pasan a tarjetas de estadística.

= 1.3.1 =
* Cambio de comportamiento con TranslatePress: las URLs traducidas (que comparten el mismo post en la base de datos que la versión original) ya no se tocan en absoluto — ni meta description, ni título, ni alt de imágenes, ni FAQ/respuesta directa/enlaces/ampliar contenido. Escribir "solo para esa URL" mezclaba idiomas en el mismo post. Se marcan con el motivo en vez de intentarlo.

= 1.3.0 =
* Nuevo: cada propuesta de IA (meta description, título, FAQ, respuesta directa, enlaces, ampliar contenido) se puede aplicar o descartar de una en una, además de en bloque. Ya no es todo o nada.
* Corrección: con TranslatePress, la IA generaba las propuestas en el idioma original del sitio aunque la página fuera de otro idioma (p. ej. escribía en español una propuesta para una URL en inglés). Ahora detecta el idioma real de la URL y escribe en ese idioma.

= 1.2.2 =
* Corrección: los modelos OpenAI más recientes (familia "o" y GPT-5) exigen el parámetro max_completion_tokens en vez de max_tokens; el plugin usaba el antiguo y todas las generaciones con IA fallaban. Ahora usa el nuevo, con reintento automático al otro si el proveedor lo rechaza.

= 1.2.1 =
* Corrección: cuando una propuesta de IA (FAQ, respuesta directa, enlaces, ampliar contenido) fallaba para todas las páginas, el mensaje era genérico ("no se pudo generar"). Ahora enseña el motivo real por página (error de la IA, post no encontrado, respuesta vacía...).

= 1.2.0 =
* Nuevo: propuesta de IA para ampliar contenido corto ("content.body_length"), con el mismo flujo de revisar y aplicar que FAQ/respuesta directa/enlaces.
* Corrección: si al actualizar el plugin ya había un informe cargado, se guarda un punto de partida en el historial para que la siguiente subida pueda compararse contra él.

= 1.1.1 =
* Corrección: las URLs traducidas por TranslatePress (que cambian también el slug, no solo el prefijo de idioma) ahora se resuelven a la página original antes de buscarla, en vez de fallar con "no se encontró el post".

= 1.1.0 =
* Nuevo: puntuación del informe por categoría, visible en el panel.
* Nuevo: historial y evolución de la puntuación entre informes subidos.
* Nuevo: las meta descriptions y títulos generados por IA ahora se muestran como propuesta editable antes de guardarse (ya no se escriben directo).
* Nuevo: propuestas de IA para FAQ, respuesta directa y enlaces internos, insertables con un clic tras revisarlas.
* Nuevo: botón "Deshacer" para cualquier cambio ya aplicado (metadatos, contenido o ajustes de plantilla).

= 1.0.0 =
* Versión inicial.
