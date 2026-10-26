use crate::basic::{Ast, Records};
use crate::basic_impact::{Impact, php_json};
use serde_json::{Value, json};
use std::collections::HashMap;

pub fn extract(ast: &Ast<'_>, records: &mut Records, impact: &Impact) -> (Vec<Value>, Vec<Value>) {
    let mut owners: HashMap<String, Vec<Value>> = HashMap::new();
    for element in &records.elements {
        if ["method", "function", "closure"].contains(&element["kind"].as_str().unwrap()) {
            owners
                .entry(element["name"].as_str().unwrap().to_owned())
                .or_default()
                .push(element.clone());
        }
    }
    let relation_start = records.relations.len();
    let diagnostic_start = records.diagnostics.len();
    for call in &impact.calls {
        let method = call["method"].as_str();
        if call["kind"] == "reference" && method.is_some_and(|name| !identifier(name)) {
            continue;
        }
        let from = call["from"].as_str().unwrap();
        let line = call["line"].as_u64().unwrap() as usize;
        let owner = if from.starts_with("(file) ") {
            records.identity(json!(["element", "file", ast.path, 0]))
        } else {
            let candidates: Vec<_> = owners
                .get(from)
                .into_iter()
                .flatten()
                .filter(|element| {
                    if let Some(offset) = call["offset"].as_u64() {
                        element["offset"].as_u64().unwrap() <= offset
                            && element["metadata"]["end_offset"]
                                .as_u64()
                                .is_some_and(|end| end >= offset)
                    } else {
                        element["line"].as_u64().unwrap() <= line as u64
                            && element["end_line"].as_u64().unwrap() >= line as u64
                    }
                })
                .collect();
            if candidates.len() != 1 {
                records.diagnostic(
                    "ambiguous_call_owner",
                    "Call belongs to several source declarations or an unresolved lexical scope.",
                    line,
                    Value::Null,
                );
                continue;
            }
            candidates[0]["id"].clone()
        };
        if call["receiver"].is_null() || method.is_none() {
            records.diagnostic(
                "unresolved_call",
                "Call receiver or method cannot be resolved from source types.",
                line,
                owner,
            );
            continue;
        }
        let mut metadata = json!({"receiver":call["receiver"],"method":call["method"],"exact_receiver":call["exact"],"form":call["site"]["form"]});
        for key in [
            "receiver_origin",
            "receiver_instance_origin",
            "json_serialization_receiver",
            "receiver_factory_call",
        ] {
            if let Some(value) = call.get(key).filter(|v| !v.is_null()) {
                metadata[key] = value.clone();
            }
        }
        metadata["offset"] = call.get("offset").cloned().unwrap_or(json!(0));
        metadata["this_receiver"] = call.get("this_receiver").cloned().unwrap_or(json!(false));
        metadata["late_static_receiver"] = call
            .get("late_static_receiver")
            .cloned()
            .unwrap_or(json!(false));
        metadata["lexical_static_receiver"] = call
            .get("lexical_static_receiver")
            .cloned()
            .unwrap_or(Value::Null);
        metadata["bound_callable"] = binding(&call["bound_callable"], &owners);
        if let Some(value) = call.get("callable_form").filter(|v| !v.is_null()) {
            metadata["callable_form"] = value.clone();
        }
        metadata["static_context"] = call["site"]["static_context"].clone();
        for key in [
            "saloon_request",
            "saloon_pool_send",
            "saloon_pool_members",
            "provider_constructor_arguments",
            "end_offset",
        ] {
            if let Some(value) = call.get(key).filter(|v| !v.is_null()) {
                metadata[key] = value.clone();
            }
        }
        let target = records.identity(json!([
            "dispatch",
            call["receiver"],
            call["method"],
            call["exact"]
        ]));
        records.relation(
            owner,
            target,
            if call["kind"] == "reference" {
                "callable-reference"
            } else {
                "calls"
            },
            line,
            call["end_line"].as_u64().unwrap_or(line as u64) as usize,
            "conditional",
            metadata,
        );
    }
    for returned in &impact.returns {
        let from = returned["from"].as_str().unwrap();
        let value = &returned["value"];
        if value["type"].is_null() && value.get("callable_name").is_none() {
            continue;
        }
        let candidates = owners.get(from).cloned().unwrap_or_default();
        let line = returned["line"].as_u64().unwrap() as usize;
        if candidates.len() != 1 {
            records.diagnostic(
                "unresolved_return",
                "Returned value or its source declaration cannot be resolved.",
                line,
                Value::Null,
            );
            continue;
        }
        let receiver = if value["type"].is_null() {
            json!(format!(
                "@named-callable:{}",
                php_json(&value["callable_name"])
            ))
        } else {
            value["type"].clone()
        };
        let mut metadata = json!({"receiver":receiver,"exact_receiver":value["exact"],"bound_callable":binding(&value["bound_callable"],&owners),"offset":returned["offset"]});
        for key in ["factory_call", "callable_form"] {
            if let Some(item) = value.get(key).filter(|v| !v.is_null()) {
                metadata[key] = item.clone();
            }
        }
        if let Some(origin) = value.get("catalog_origin") {
            metadata["value_origin"] = origin.clone();
        }
        let target = records.identity(json!(["value", metadata]));
        records.relation(
            candidates[0]["id"].clone(),
            target,
            "returns-value",
            line,
            returned["end_line"].as_u64().unwrap() as usize,
            "conditional",
            metadata,
        );
    }
    for notice in &impact.notices {
        let reason = notice["reason"].as_str().unwrap();
        records.diagnostic(
            if reason.to_ascii_lowercase().contains("limit") {
                "call_limit"
            } else {
                "call_analysis"
            },
            reason,
            notice["line"].as_u64().unwrap() as usize,
            Value::Null,
        );
    }
    (
        records.relations.split_off(relation_start),
        records.diagnostics.split_off(diagnostic_start),
    )
}

fn identifier(name: &str) -> bool {
    !name.is_empty()
        && name.bytes().enumerate().all(|(index, b)| {
            b.is_ascii_alphabetic() || b == b'_' || b >= 128 || index > 0 && b.is_ascii_digit()
        })
}

fn binding(value: &Value, owners: &HashMap<String, Vec<Value>>) -> Value {
    let Some(creator) = value["creator"].as_str() else {
        return Value::Null;
    };
    let Some(candidates) = owners.get(creator).filter(|v| v.len() == 1) else {
        return Value::Null;
    };
    let mut value = value.clone();
    value["creator"] = candidates[0]["id"].clone();
    value
}
