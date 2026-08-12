# Haydi

Haydi is a WordPress administrator assistant that lets a model inspect a site and propose or perform site-management work through registered tools.

## Language

**Tool**:
A named capability that Haydi can expose to a model or remote MCP client.
_Avoid_: Function, command

**Tool Declaration**:
The canonical name, description, JSON Schema, exposure, and execution policy of a Tool.
_Avoid_: Tool schema, function definition

**Tool Execution**:
The JSON-compatible structured result of invoking a Tool Implementation with normalized arguments through an authorized surface. It remains native data until a transport Adapter requires text.
_Avoid_: Tool response, callback result

**Action Proposal**:
A side-effect-free request for a human to approve a mutating Tool before its Tool Execution.
_Avoid_: Pending write, pending install

**Approval Pipeline**:
The single lifecycle that presents an Action Proposal, records the human decision, and—only after approval—routes the Tool to its registered Implementation for Tool Execution.
_Avoid_: Approval handler, pending-action type

**Provider Continuation**:
Server-only, provider-originated state required to resume an active assistant Tool turn with the exact provider and model that produced it. It may contain reasoning, signatures, response handles, or other Adapter-owned metadata. The browser receives only a short-lived opaque handle; transient storage is authenticated and encrypted with the site's WordPress authentication salt.
_Avoid_: DeepSeek reasoning, hidden transcript, chain-of-thought cache

**Sanitized Transcript**:
The provider-neutral, browser-safe conversation projection. It contains visible content and Tool lifecycle data, but never Provider Continuation.
_Avoid_: Provider transcript, raw history

## Relationships

- A **Tool** has exactly one **Tool Declaration**
- A **Tool Declaration** resolves to exactly one Tool Implementation
- A read-only **Tool** produces a **Tool Execution** immediately
- A mutating **Tool** produces an **Action Proposal** in chat and a **Tool Execution** only after authorization
- Every chat **Action Proposal** passes through the same **Approval Pipeline**
- A declined **Action Proposal** resolves the provider transcript without producing a **Tool Execution**
- A **Tool Execution** is serialized only by the transport Adapter that requires text
- An active assistant Tool turn may own one **Provider Continuation**
- A **Provider Continuation** is bound to its user, login session, originating provider and model, Tool call IDs, and **Sanitized Transcript** prefix
- Only its originating provider and model may consume a **Provider Continuation**, and consumption is one-shot
- A completed turn, provider or model switch, mismatch, or expiry discards **Provider Continuation**; only the **Sanitized Transcript** remains

## Example dialogue

> **Developer:** "Should the chat dispatcher decide whether `edit` needs approval?"
> **Domain expert:** "No. Its Tool Declaration identifies it as mutating, so chat receives an Action Proposal while authenticated MCP receives a Tool Execution."

> **Developer:** "Should plugin activation have its own pending response and AJAX executor?"
> **Domain expert:** "No. Presentation may vary by Tool, but the Action Proposal and approved Tool Execution use the same Approval Pipeline."

> **Developer:** "Should `list_plugins` JSON-encode its result for OpenAI?"
> **Domain expert:** "No. Its Tool Implementation returns structured data. The provider or transcript Adapter performs any required serialization."

> **Developer:** "Should Haydi store DeepSeek `reasoning_content` in the chat?"
> **Domain expert:** "No. Haydi stores a provider-neutral Provider Continuation only while the Tool turn is active. The DeepSeek Adapter maps that state to its wire field, while the browser and saved chat receive only the Sanitized Transcript."

## Flagged ambiguities

- "Tool schema" previously meant both the AI declaration and the MCP declaration; resolved: both are projections of one **Tool Declaration**.
- "Approval flow" previously meant separate file, SQL, PHP, and plugin browser paths; resolved: presentation details sit inside one **Approval Pipeline**.
- "Tool result" previously meant both native data and pre-encoded JSON text; resolved: a **Tool Execution** stays structured and serialization belongs to an Adapter.
- "Reasoning state" previously suggested a DeepSeek-only concern; resolved: reasoning and signatures are forms of provider-owned **Provider Continuation**.
