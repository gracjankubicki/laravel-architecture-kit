use crate::basic::{Ast, Records};
use serde_json::{Value, json};

pub const PRODUCT: &str = "basic-file-facts-v1";

pub fn limited(request: &Value) -> Result<Option<Value>, Box<dyn std::error::Error>> {
    let options = &request["options"];
    for key in ["impact", "catalog", "preserve_occurrences"] {
        if !options[key].is_boolean() {
            return Err("Invalid Basic extraction options".into());
        }
    }
    if request["extractor"] != 1 {
        return Err("Unsupported Basic extractor schema".into());
    }
    for key in [
        "engine_fingerprint",
        "configuration_fingerprint",
        "mapping_fingerprint",
    ] {
        if !request[key].is_string() {
            return Err("Missing Basic extraction identity".into());
        }
    }
    if (options["impact"] == true || options["catalog"] == true)
        && original(request)?.len() > 100000
    {
        return response(
            request,
            json!({"nodes":[],"roots":[]}),
            Vec::new(),
            false,
            Some("Impact source size limit exceeded."),
        )
        .map(Some);
    }
    Ok(None)
}

pub fn response(
    request: &Value,
    mut syntax: Value,
    mut diagnostics: Vec<Value>,
    ir_built: bool,
    limit: Option<&str>,
) -> Result<Value, Box<dyn std::error::Error>> {
    let original = original(request)?;
    let path = request["path"].as_str().ok_or("Missing Basic path")?;
    let mapping = Mapping::new(request, original.len())?;
    if syntax["nodes"]
        .as_array()
        .is_none_or(|nodes| nodes.len() > 100000)
    {
        return Err(
            "Portable syntax exceeds its 100000 node budget. Analysis is incomplete.".into(),
        );
    }
    for node in syntax["nodes"].as_array_mut().unwrap() {
        let start = node["start"].as_u64().ok_or("Invalid syntax start")? as usize;
        let end = node["end"].as_u64().ok_or("Invalid syntax end")? as usize;
        let start = mapping.position(start);
        let end = if end == node["start"].as_u64().unwrap() as usize {
            start
        } else {
            mapping.position(end.saturating_sub(1)) + 1
        };
        node["start"] = json!(start);
        node["end"] = json!(end.max(start));
    }
    for diagnostic in &mut diagnostics {
        let start = diagnostic["start"]
            .as_u64()
            .map(|s| mapping.position(s as usize));
        let end = diagnostic["end"].as_u64().map(|e| {
            if let Some(s) = diagnostic["start"].as_u64() {
                if e == s {
                    mapping.position(s as usize)
                } else {
                    mapping.position(e.saturating_sub(1) as usize) + 1
                }
            } else {
                mapping.position(e as usize)
            }
        });
        diagnostic["start"] = json!(start);
        diagnostic["end"] = json!(end.map(|e| e.min(original.len())));
    }
    let ast = Ast {
        syntax: &syntax,
        path,
        source: original,
    };
    let impact = request["options"]["impact"] == true;
    let catalog = request["options"]["catalog"] == true;
    let error = diagnostics
        .first()
        .map(|d| d["message"].clone())
        .unwrap_or(Value::Null);
    let error_line = diagnostics
        .first()
        .and_then(|d| d["start"].as_u64())
        .map(|s| ast.line(s as usize))
        .unwrap_or(1);
    let mut records = Records::default();
    let mut symbols = Vec::new();
    let mut edges = Vec::new();
    let mut invocations = Vec::new();
    let mut impact_facts = Value::Null;
    let mut catalog_facts = Value::Null;
    if !diagnostics.is_empty() || limit.is_some() {
        if impact {
            impact_facts = json!({"classes":{},"calls":[],"notices":[{"line":1,"reason":limit.unwrap_or("Unparseable source.")} ]});
        }
        if catalog {
            let file = records.element(
                &ast,
                "file",
                path,
                0,
                1,
                ast.line(original.len()),
                Value::Null,
                json!({}),
            );
            records.diagnostic(
                if limit.is_some() {
                    "source_limit"
                } else {
                    "parse_error"
                },
                limit.unwrap_or_else(|| error.as_str().unwrap()),
                1,
                file,
            );
        }
    } else {
        let structure = crate::basic_structure::from_ir(
            &ast,
            request["options"]["preserve_occurrences"] == true,
        );
        symbols = structure.declarations;
        edges = structure.edges;
        let tests = crate::basic_tests::invocations(&ast);
        invocations = tests.records.clone();
        let calls = if impact || catalog {
            Some(crate::basic_impact::extract(&ast, true))
        } else {
            None
        };
        if impact {
            let calls = calls.as_ref().unwrap();
            impact_facts =
                json!({"classes":calls.classes,"calls":calls.calls,"notices":calls.notices});
        }
        if catalog {
            records = crate::basic::declarations(&ast);
            let calls = calls.as_ref().unwrap();
            let (call_relations, call_diagnostics) =
                crate::basic_calls::extract(&ast, &mut records, calls);
            let (container_relations, container_diagnostics) =
                crate::basic_container::extract(&ast, &mut records);
            let (test_elements, test_relations, test_diagnostics) =
                crate::basic_tests::catalog(&ast, &mut records, &tests);
            records.elements.extend(test_elements);
            records.relations.extend(test_relations);
            records.relations.extend(call_relations);
            records.relations.extend(container_relations);
            records.diagnostics.extend(test_diagnostics);
            records.diagnostics.extend(call_diagnostics);
            records.diagnostics.extend(container_diagnostics);
            catalog_facts = json!({"v":3,"elements":records.elements,"relations":records.relations,"diagnostics":records.diagnostics,"descriptor_receivers":calls.descriptors});
        }
    }
    if catalog && catalog_facts.is_null() {
        catalog_facts = json!({"v":3,"elements":records.elements,"relations":records.relations,"diagnostics":records.diagnostics,"descriptor_receivers":{}});
    }
    Ok(
        json!({"schema":1,"product":PRODUCT,"extractor":1,"raw_schema":1,"path":path,"source_hash":request["source_hash"],"php_version":request["php_version"],
        "engine_fingerprint":request["engine_fingerprint"],"configuration_fingerprint":request["configuration_fingerprint"],"mapping_fingerprint":request["mapping_fingerprint"],
        "identities":records.identities,"entry":{"symbols":symbols,"edges":edges,"test_invocations":invocations,"impact":impact_facts,"catalog":catalog_facts,"syntax_error":error,"syntax_error_line":error_line},
        "diagnostics":diagnostics,"trace":{"product":PRODUCT,"syntax_transport":false,"ir_built":ir_built,"ir_nodes":syntax["nodes"].as_array().unwrap().len(),"source_bytes":original.len(),"prepared_bytes":request["source"].as_str().unwrap().len()}}),
    )
}

fn original(request: &Value) -> Result<&[u8], Box<dyn std::error::Error>> {
    request
        .get("original_source")
        .unwrap_or(&request["source"])
        .as_str()
        .map(str::as_bytes)
        .ok_or_else(|| "Missing Basic original source".into())
}

struct Segment {
    start: usize,
    original: usize,
    length: usize,
    copied: bool,
}
struct Mapping {
    segments: Vec<Segment>,
    prepared: usize,
    original: usize,
}
impl Mapping {
    fn new(request: &Value, original: usize) -> Result<Self, Box<dyn std::error::Error>> {
        let prepared = request["source"]
            .as_str()
            .ok_or("Missing prepared source")?
            .len();
        let mut segments = Vec::new();
        let mut cursor = 0;
        if let Some(mapping) = request.get("mapping") {
            for item in mapping.as_array().ok_or("Invalid Basic mapping")? {
                let start = item["start"].as_u64().ok_or("Invalid mapping start")? as usize;
                let base = item["original"].as_u64().ok_or("Invalid mapping origin")? as usize;
                let length = item["length"].as_u64().ok_or("Invalid mapping length")? as usize;
                let copied = item["copied"].as_bool().ok_or("Invalid mapping mode")?;
                if start != cursor
                    || length == 0
                    || base > original
                    || copied && base.checked_add(length).is_none_or(|end| end > original)
                {
                    return Err("Basic mapping is outside the source snapshot".into());
                }
                cursor = cursor.checked_add(length).ok_or("Basic mapping overflow")?;
                segments.push(Segment {
                    start,
                    original: base,
                    length,
                    copied,
                });
            }
            if cursor != prepared
                || request["mapping_fingerprint"]
                    .as_str()
                    .unwrap_or("")
                    .is_empty()
            {
                return Err("Incomplete Basic byte mapping".into());
            }
        } else if request.get("original_source").is_some() || request["mapping_fingerprint"] != "" {
            return Err("Prepared source needs an explicit byte mapping".into());
        }
        Ok(Self {
            segments,
            prepared,
            original,
        })
    }
    fn position(&self, offset: usize) -> usize {
        if self.segments.is_empty() {
            return offset.min(self.original);
        }
        if offset >= self.prepared {
            return self.original;
        }
        let index = self
            .segments
            .partition_point(|segment| segment.start <= offset)
            .saturating_sub(1);
        let segment = &self.segments[index];
        if offset >= segment.start + segment.length {
            return offset.min(self.original);
        }
        segment.original
            + if segment.copied {
                offset - segment.start
            } else {
                0
            }
    }
}
