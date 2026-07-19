=== scanGEO Fixer ===
Contributors: scangeo
Tags: seo, geo, ai, schema, audit
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.9.4
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

1. Sube la carpeta `scangeo-fixer` a `/wp-content/plugins/` o instala el .zip desde Plugins → Añadir nuevo.
2. Activa el plugin.
3. (Opcional) En scanGEO Fixer → Ajustes, añade tu clave API de Anthropic u OpenAI para generar textos con IA, y tus perfiles sociales para el schema.
4. Ve a scanGEO Fixer, sube el .md exportado por scanGEO.app y pulsa "Reparar todo".

== Changelog ==

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
