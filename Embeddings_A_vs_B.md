# Embeddings tras migrar a Anthropic — ¿A o B?

**Contexto:** el plugin migra las llamadas de IA de OpenAI a Anthropic (Claude). Pero Anthropic
**no ofrece API de embeddings**, y el RAG del plugin los necesita. Opción C (desactivar RAG)
descartada por el jefe. Quedan:

- **A** — mantener **OpenAI** solo para embeddings (`text-embedding-3-small`), Anthropic para el resto.
- **B** — mover embeddings a otro proveedor. El recomendado por la propia Anthropic es **Voyage AI**.

> Nota importante: **ni A ni B dejan un solo proveedor.** En ambos casos hay Anthropic + un
> segundo servicio (OpenAI en A, Voyage en B). B no simplifica el número de proveedores.

---

## Ventajas de B (Voyage) sobre A (OpenAI) — lo que pidió estudiar

1. **Mejor calidad de recuperación (el argumento más fuerte).** Los modelos actuales de Voyage
   (`voyage-4`, `voyage-4-large`) están entre lo mejor en *retrieval* y superan en benchmarks a
   `text-embedding-3-small`. Como el RAG es la parte más floja del plugin (resúmenes, contenido de
   PDF/SCORM), mejores embeddings = respuestas de contenido más relevantes.

2. **Multilingüe de serie.** Voyage está optimizado para recuperación multilingüe. El contenido es
   español y hemos visto consultas en inglés; encaja bien.

3. **Extras que OpenAI no tiene:**
   - **Reranker** (`rerank-2.5`): reordena los fragmentos por relevancia antes de pasárselos al
     modelo. Es la palanca más directa para mejorar el RAG sin cambiar nada más.
   - **Embeddings de chunk contextualizados** (`voyage-context-4`): cada fragmento "sabe" del
     documento completo, mejora la recuperación sin metadatos manuales.

4. **Opción open-weight (`voyage-4-nano`, Apache 2.0).** Se puede autoalojar → coste por llamada
   cero y control total del dato, si algún día se quiere quitar toda dependencia externa.

5. **Dimensiones flexibles (256/512/1024/2048) y contexto de 32K.** Permite ajustar tamaño de la
   base de vectores y velocidad; y embeber fragmentos más grandes (OpenAI se queda en 8.191 tokens).

6. **Salir de OpenAI por completo**, si hubiera una razón de contrato, política de datos o de
   proveedor para no usarlo. (No es una ventaja técnica, sino de política.)

---

## Lo que sigue jugando a favor de A (contrapeso honesto)

1. **Coste ≈ irrelevante y a favor de A.** `text-embedding-3-small` es baratísimo y el uso es
   esporádico (solo al crear/actualizar el curso). El ahorro de cambiar a B es ~cero; Voyage suele
   costar más por token en sus modelos de calidad.
2. **Madurez y "ya lo tenemos".** Cuenta creada, código funcionando, embeddings de OpenAI muy
   probados. B introduce una integración nueva que mantener.
3. **Coste de migración real:** cambiar de proveedor obliga a **reindexar TODO** el contenido
   existente. Los vectores guardados son de OpenAI (1536 dim); Voyage usa otro espacio (1024 dim por
   defecto), no son intercambiables → hay que volver a embeber todos los cursos.
4. **B no reduce proveedores** (sigue habiendo dos: Anthropic + Voyage).

---

## Lectura y recomendación

- **Si el objetivo es coste, simplicidad y mínimo riesgo → A.** Es lo que sostiene el jefe y los
  hechos lo respaldan: barato, maduro, ya montado, uso bajo.
- **B solo compensa si se busca una de estas dos cosas:**
  1. **Subir la calidad del RAG** (Voyage-4 + reranker + chunks contextualizados). Es una mejora de
     producto real, porque el RAG es hoy el punto débil — pero es una **inversión de calidad**, no
     de coste ni de simplicidad, y se puede hacer **más adelante**, no es parte de la migración a
     Anthropic.
  2. **Eliminar OpenAI por completo** por una razón de política/contrato/datos.

**Propuesta:** migrar ahora a Anthropic con la **opción A** (mínimo riesgo, RAG intacto) y dejar
Voyage como **mejora futura opcional** para el RAG, evaluándola por su ganancia de calidad — no como
requisito de la migración. Así se desacopla "cambiar la key del modelo" (lo urgente) de "mejorar los
embeddings" (opcional y evaluable con calma).

---

*Fuentes: documentación de Anthropic sobre embeddings (recomienda Voyage AI; Anthropic no ofrece
modelo propio de embeddings) y catálogo de modelos Voyage (voyage-4 / voyage-4-large / -lite / -nano,
rerank-2.5, voyage-context-4). Precios exactos: ver la página de precios de Voyage y OpenAI, que
conviene confirmar en el momento de decidir.*
