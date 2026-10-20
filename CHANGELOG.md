CHANGELOG
=========

0.2
---

 * Add support for `Document` content in user messages: documents are converted to text via 506.ai's
   `extractText` endpoint (`extractTextOcr` for PDFs without a text layer) and inlined into the message
 * Add `TextExtractor`; `MessageBagNormalizer` takes it as optional constructor argument
 * Announce `Capability::INPUT_PDF` for all models
 * Require `symfony/mime`

0.1
---

 * Add the bridge: chat (`chatNoStream`), streaming (`chat`) and structured output (`generateStructuredOutput`)
 * Add `CORSAi506Bundle` to register the platform as `ai.platform.ai506`
