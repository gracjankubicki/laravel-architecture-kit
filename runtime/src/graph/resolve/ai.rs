//! Only signature proof reachable from Basic argument flow. No AI effect resolver.
use super::*;
fn at_least(version: &str, min: &str) -> bool {
    let parse = |s: &str| {
        s.split('.')
            .map(|v| v.parse::<u32>().unwrap_or(0))
            .collect::<Vec<_>>()
    };
    parse(version) >= parse(min)
}
impl Resolver<'_> {
    fn ai_profile(&self) -> Option<String> {
        let mut versions = vec![];
        for e in self.state.elements() {
            if e.get("kind").text() != "composer-package" || e.get("name").text() != "laravel/ai" {
                continue;
            }
            let version = e.meta("normalized_version").string().ok()?;
            let reference =
                super::super::compose::constants::inspected_reference("laravel/ai", version)?;
            let state = e
                .get("metadata")
                .find("reference_state")
                .map(Value::text)
                .unwrap_or("unknown");
            if state == "unknown"
                || state == "known" && e.meta("source_reference").text() != reference
            {
                return None;
            }
            versions.push(version.to_string())
        }
        let versions = unique(versions);
        if versions.len() == 1 {
            Some(versions[0].clone())
        } else {
            None
        }
    }
    fn has_contract(&mut self, id: &str, contract: &str) -> bool {
        if self
            .state
            .element(id)
            .get("name")
            .text()
            .eq_ignore_ascii_case(contract)
        {
            return true;
        }
        super::super::compose::contracts(self.state, id, vec![])
            .find(&lower(contract))
            .is_some()
    }

    fn provider_channels(version: &str) -> Vec<(String, Vec<String>)> {
        let mut rows: Vec<(String, Vec<String>)> = vec![];
        for (name, channels) in [
            ("Anthropic", "File Text"),
            ("AzureOpenAi", "Embedding Image Text"),
            ("Bedrock", "Embedding Image Text"),
            ("Cohere", "Embedding Reranking"),
            ("DeepSeek", "Text"),
            ("ElevenLabs", "Audio Transcription"),
            (
                "Gemini",
                "Audio Embedding File Image Store Text Transcription",
            ),
            ("Groq", "Text"),
            ("Jina", "Embedding Reranking"),
            ("Mistral", "Embedding Text Transcription"),
            ("Ollama", "Embedding Text"),
            (
                "OpenAi",
                "Audio Embedding File Image Store Text Transcription",
            ),
            ("OpenRouter", "Audio Embedding Image Text Transcription"),
            ("VoyageAi", "Embedding Reranking"),
            ("Xai", "Image Text"),
        ] {
            rows.push((
                format!("Laravel\\Ai\\Providers\\{name}Provider"),
                channels.split(' ').map(String::from).collect(),
            ))
        }
        let mut set = |name: &str, channels: &str| {
            let name = format!("Laravel\\Ai\\Providers\\{name}Provider");
            if let Some((_, cs)) = rows.iter_mut().find(|(n, _)| *n == name) {
                *cs = channels.split(' ').map(String::from).collect()
            } else {
                rows.push((name, channels.split(' ').map(String::from).collect()))
            }
        };
        if at_least(version, "0.9.0.0") {
            set("AzureOpenAi", "Embedding File Image Store Text");
            set("OpenAiCompatible", "Text")
        }
        if at_least(version, "0.10.3.0") {
            set("OpenAiCompatible", "Embedding Text")
        }
        if at_least(version, "0.11.0.0") {
            set("Groq", "Text Transcription");
            set("OpenAiCompatible", "Embedding Text Transcription")
        }
        if at_least(version, "0.11.1.0") {
            set("Bedrock", "Embedding Image Text Reranking");
            set("Mistral", "Embedding Text Transcription Audio")
        }
        rows
    }
    fn provider_ancestors(&mut self, ty: &str, version: &str) -> Vec<String> {
        let ids = self.named_types(ty);
        if ids.len() > 1
            || !self
                .named_types("Laravel\\Ai\\Providers\\Provider")
                .is_empty()
        {
            return vec![];
        }
        let mut out = vec![];
        for (sdk, _) in Self::provider_channels(version) {
            if self.named_types(&sdk).is_empty()
                && (ty.eq_ignore_ascii_case(&sdk)
                    || ids.len() == 1 && self.has_contract(&ids[0], &sdk))
            {
                out.push(sdk)
            }
        }
        out
    }
    fn gateway_contracts(version: &str) -> Vec<(String, Vec<String>)> {
        let prefix = "Laravel\\Ai\\Contracts\\Gateway\\";
        let text = if at_least(version, "0.9.0.0") {
            "StepText"
        } else {
            "Text"
        };
        let shared: Vec<_> = [
            "Gateway",
            "AudioGateway",
            "EmbeddingGateway",
            "ImageGateway",
            "TranscriptionGateway",
        ]
        .into_iter()
        .map(|v| format!("{prefix}{v}"))
        .chain([format!("{prefix}{text}Gateway")])
        .collect();
        vec![
            (
                "Laravel\\Ai\\Gateway\\OpenAi\\OpenAiGateway".into(),
                shared.clone(),
            ),
            (
                "Laravel\\Ai\\Gateway\\Gemini\\GeminiGateway".into(),
                shared.clone(),
            ),
            (
                "Laravel\\Ai\\Gateway\\Anthropic\\AnthropicGateway".into(),
                shared,
            ),
            (
                "Laravel\\Ai\\Gateway\\OpenAi\\OpenAiFileGateway".into(),
                vec![format!("{prefix}FileGateway")],
            ),
            (
                "Laravel\\Ai\\Gateway\\OpenAi\\OpenAiStoreGateway".into(),
                vec![format!("{prefix}StoreGateway")],
            ),
        ]
    }
    fn gateway_matches(&mut self, ty: &str, contract: &str, version: &str) -> bool {
        let ids = self.named_types(ty);
        if ty.eq_ignore_ascii_case(contract)
            || ids.len() == 1 && self.has_contract(&ids[0], contract)
        {
            return true;
        }
        let aggregate = "Laravel\\Ai\\Contracts\\Gateway\\Gateway";
        let contracts = Self::gateway_contracts(version);
        if self.named_types(aggregate).is_empty()
            && self.named_types(contract).is_empty()
            && (ty.eq_ignore_ascii_case(aggregate)
                || ids.len() == 1 && self.has_contract(&ids[0], aggregate))
            && contracts[0].1.iter().any(|c| c == contract)
        {
            return true;
        }
        if ids.len() > 1 {
            return false;
        }
        for (sdk, cs) in contracts {
            if !self.named_types(&sdk).is_empty() {
                continue;
            }
            let mut shadow = false;
            for c in &cs {
                if !self.named_types(c).is_empty() {
                    shadow = true
                }
            }
            if shadow {
                continue;
            }
            if (ty.eq_ignore_ascii_case(&sdk) || ids.len() == 1 && self.has_contract(&ids[0], &sdk))
                && cs.iter().any(|c| c.eq_ignore_ascii_case(contract))
            {
                return true;
            }
        }
        false
    }
    pub(super) fn ai_setter(&mut self, a: &[serde_json::Value], depth: usize) -> bool {
        let Some(method) = a[2].as_str().map(lower) else {
            return false;
        };
        let channels = [
            "audio",
            "embedding",
            "file",
            "image",
            "reranking",
            "store",
            "text",
            "transcription",
        ];
        let Some(channel) = channels
            .into_iter()
            .find(|c| method == format!("use{c}gateway"))
        else {
            return false;
        };
        if a[4]["form"] != "instance"
            || a[5].as_array().is_none_or(|v| v.len() != 1)
            || !matches!(a[5][0]["name"].as_str(), None | Some("gateway"))
        {
            return false;
        }
        let Some(version) = self.ai_profile() else {
            return false;
        };
        let mut channel = channel.to_string();
        channel[0..1].make_ascii_uppercase();
        let trait_name = format!("Laravel\\Ai\\Providers\\Concerns\\Has{channel}Gateway");
        let contract = format!(
            "Laravel\\Ai\\Contracts\\Gateway\\{}Gateway",
            if channel == "Text" && at_least(&version, "0.9.0.0") {
                "StepText"
            } else {
                &channel
            }
        );
        let provider = format!("Laravel\\Ai\\Contracts\\Providers\\{channel}Provider");
        for name in [&trait_name, &contract, &provider] {
            if !self.named_types(name).is_empty() {
                return false;
            }
        }
        let types = self.types(a[1].as_str().unwrap_or(""), depth + 1);
        let gateway_types = self.types(a[0].as_str().unwrap_or(""), depth + 1);
        if types.is_empty() || gateway_types.is_empty() || self.limited {
            return false;
        }
        for ty in gateway_types {
            if !self.gateway_matches(&ty, &contract, &version) {
                return false;
            }
        }
        let inventory: Vec<_> = [
            "Audio",
            "Embedding",
            "File",
            "Image",
            "Reranking",
            "Store",
            "Text",
            "Transcription",
        ]
        .into_iter()
        .map(|c| {
            let mut methods = vec![
                format!("{}gateway", lower(c)),
                format!("use{}gateway", lower(c)),
            ];
            if c == "Text" && at_least(&version, "0.9.0.0") {
                methods.push("textgenerationloop".into())
            }
            (
                format!("Laravel\\Ai\\Providers\\Concerns\\Has{c}Gateway"),
                methods,
            )
        })
        .collect();
        for ty in types {
            let ids = self.named_types(&ty);
            let ancestors = self.provider_ancestors(&ty, &version);
            let standard = Self::provider_channels(&version)
                .iter()
                .any(|(sdk, channels)| ancestors.contains(sdk) && channels.contains(&channel));
            if !standard && !(ids.len() == 1 && self.has_contract(&ids[0], &trait_name)) {
                return false;
            }
            let mut candidates = ids.clone();
            if !a[3].as_bool().unwrap_or(false) {
                if ids.len() == 1 {
                    candidates.extend(self.subtypes.get(&ids[0]).cloned().unwrap_or_default())
                } else {
                    let classes: Vec<_> = self
                        .state
                        .elements()
                        .filter(|e| e.get("kind").text() == "class")
                        .map(|e| {
                            (
                                e.get("id").text().to_string(),
                                e.get("name").text().to_string(),
                            )
                        })
                        .collect();
                    for (id, name) in classes {
                        if self
                            .provider_ancestors(&name, &version)
                            .iter()
                            .any(|sdk| sdk.eq_ignore_ascii_case(&ty))
                        {
                            candidates.push(id)
                        }
                    }
                }
            }
            if candidates.len() > 1000 {
                self.limited = true;
                return false;
            }
            for id in unique(candidates) {
                self.failed = false;
                self.factory_visits = 0;
                let previous = std::mem::replace(&mut self.known_traits, inventory.clone());
                let method = self.source_method(&id, &method, vec![]);
                self.known_traits = previous;
                if self.limited || self.failed || method.is_some() {
                    return false;
                }
            }
        }
        true
    }
}
