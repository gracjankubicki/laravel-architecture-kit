//! Local structural contribution from private normalized syntax.
//!
//! This module is not a full Basic product: catalog, impact and test extraction
//! must be composed before advertising basic-file-facts-v1 on the wire.
use serde_json::{Value, json};
use std::collections::HashSet;

pub struct Structure {
    /// Role classification is applied by PHP using the pinned project settings.
    pub declarations: Vec<Value>,
    pub edges: Vec<Value>,
}

pub fn from_ir(ast: &crate::basic::Ast<'_>, preserve: bool) -> Structure {
    let roots = ast.roots();
    let classless = !ast.path.starts_with("app/")
        && !roots
            .iter()
            .flat_map(|n| n.descendants())
            .any(|n| n.class_like() && n.child("name").is_some());
    let mut result = Structure {
        declarations: Vec::new(),
        edges: Vec::new(),
    };
    let source = if classless {
        let name = format!("(file) {}", ast.path);
        result
            .declarations
            .push(json!([name, 1, "", "file", false, true]));
        Some(name)
    } else {
        None
    };
    for root in roots {
        ir_visit(root, source.as_deref(), &HashSet::new(), &mut result);
    }
    let mut seen = HashSet::new();
    result.edges.retain(|edge| {
        if edge[0]
            .as_str()
            .unwrap()
            .eq_ignore_ascii_case(edge[1].as_str().unwrap())
        {
            return false;
        }
        preserve
            || seen.insert(
                format!(
                    "{}|{}|{}|{}|{}",
                    edge[0].as_str().unwrap(),
                    edge[1].as_str().unwrap(),
                    ast.path,
                    edge[2],
                    edge[3].as_str().unwrap()
                )
                .to_ascii_lowercase(),
            )
    });
    result
}
fn ir_visit(
    node: crate::basic::N<'_>,
    source: Option<&str>,
    relations: &HashSet<usize>,
    out: &mut Structure,
) {
    let mut declared = None;
    if node.class_like() && node.child("name").is_some() {
        declared = node.declared();
        if let Some(name) = &declared {
            let kind = node.kind().strip_prefix("statement.").unwrap();
            let has = node
                .list("stmts")
                .iter()
                .any(|n| n.kind() == "statement.class-method");
            let namespace = name.rsplit_once('\\').map_or("", |(s, _)| s);
            out.declarations.push(json!([
                name,
                node.line(),
                namespace,
                kind,
                has,
                kind != "interface" || has
            ]));
        }
    }
    let source = declared.as_deref().or(source);
    let mut relations = relations.clone();
    if let Some(source) = source {
        let mut add = |name: Option<crate::basic::N<'_>>, line: usize, kind: &str, strong: bool| {
            if let Some(name) = name.filter(|n| {
                n.is_name()
                    && !["self", "static", "parent"]
                        .contains(&n.name().to_ascii_lowercase().as_str())
            }) {
                out.edges
                    .push(json!([source, name.resolved(), line, kind, strong]));
            }
        };
        match node.kind() {
            "statement.class" => {
                add(node.child("extends"), node.line(), "extends", true);
                for name in node.list("implements") {
                    add(Some(name), name.line(), "implements", true);
                }
            }
            "statement.interface" => {
                for name in node.list("extends") {
                    add(Some(name), name.line(), "extends", true);
                }
            }
            "statement.enum" => {
                for name in node.list("implements") {
                    add(Some(name), name.line(), "implements", true);
                }
            }
            "statement.trait-use" => {
                for name in node.list("traits") {
                    add(Some(name), name.line(), "trait", true);
                }
            }
            "param" => ir_type(node.child("type"), node.line(), "parameter", &mut add),
            "statement.class-method" => {
                ir_type(node.child("returnType"), node.line(), "return", &mut add)
            }
            "statement.property" | "statement.class-const" => {
                ir_type(node.child("type"), node.line(), "property", &mut add)
            }
            "expression.new" => add(node.child("class"), node.line(), "new", true),
            "expression.static-call" | "expression.static-property-fetch" => {
                add(node.child("class"), node.line(), "static", true)
            }
            "expression.instanceof" => add(node.child("class"), node.line(), "instanceof", true),
            "statement.catch" => {
                for name in node.list("types") {
                    add(Some(name), name.line(), "catch", true);
                }
            }
            "attribute" => add(node.child("name"), node.line(), "attribute", true),
            "expression.method-call"
                if node.child("name").is_some_and(|n| {
                    n.kind() == "identifier"
                        && [
                            "belongsto",
                            "belongstomany",
                            "hasmany",
                            "hasmanythrough",
                            "hasone",
                            "hasonethrough",
                            "morphone",
                            "morphmany",
                            "morphto",
                            "morphtomany",
                            "morphedbymany",
                        ]
                        .contains(&n.name().to_ascii_lowercase().as_str())
                }) =>
            {
                for arg in node.list("args") {
                    if let Some(value) = arg.child("value").filter(|n| {
                        n.kind() == "expression.class-const-fetch"
                            && n.child("class").is_some_and(|n| n.is_name())
                    }) {
                        relations.insert(value.id);
                        add(
                            value.child("class"),
                            value.line(),
                            "eloquent-relation",
                            false,
                        );
                    }
                }
            }
            "expression.class-const-fetch" => {
                let class = node.named("name").eq_ignore_ascii_case("class");
                if !class || !relations.contains(&node.id) {
                    add(
                        node.child("class"),
                        node.line(),
                        if class {
                            "class-reference"
                        } else {
                            "class-constant"
                        },
                        !class,
                    );
                }
            }
            _ => {}
        }
    }
    for child in node.children() {
        ir_visit(child, source, &relations, out);
    }
}
fn ir_type<'a>(
    node: Option<crate::basic::N<'a>>,
    line: usize,
    kind: &str,
    add: &mut impl FnMut(Option<crate::basic::N<'a>>, usize, &str, bool),
) {
    if let Some(node) = node {
        if node.is_name() {
            add(Some(node), line, kind, true);
        } else if node.kind() == "nullable-type" {
            ir_type(node.child("type"), line, kind, add);
        } else if matches!(node.kind(), "union-type" | "intersection-type") {
            for part in node.list("types") {
                ir_type(Some(part), line, kind, add);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use mago_allocator::LocalArena;
    use mago_database::file::FileId;
    use mago_names::resolver::NameResolver;
    use mago_syntax::parser::parse_file_content;

    fn structure(source: &str, path: &str, preserve: bool) -> Structure {
        let arena = LocalArena::new();
        let program = parse_file_content(&arena, FileId::new(path.as_bytes()), source.as_bytes());
        assert!(program.errors.is_empty());
        let names = NameResolver::new(&arena).resolve(program);
        let syntax = crate::syntax::document(program, &names)
            .unwrap_or_else(|error| panic!("{}", error.message));
        let ast = crate::basic::Ast {
            syntax: &syntax,
            path,
            source: source.as_bytes(),
        };
        from_ir(&ast, preserve)
    }
    #[test]
    fn keeps_aliases_and_structural_dependency_strength() {
        let result = structure(
            "<?php namespace App; use Vendor\\Port as Gateway; class Runner implements Gateway { function run(Gateway $port): Gateway { new Gateway; Gateway::run(); Gateway::class; } }",
            "app/Runner.php",
            false,
        );
        assert_eq!(
            result.declarations,
            json!([["App\\Runner", 1, "App", "class", true, true]])
                .as_array()
                .unwrap()
                .clone()
        );
        assert_eq!(result.edges.len(), 6);
        assert!(result.edges.iter().all(|edge| edge[1] == "Vendor\\Port"));
        assert_eq!(result.edges[5][3], "class-reference");
        assert_eq!(result.edges[5][4], false);
    }
    #[test]
    fn classless_sources_keep_occurrences_and_app_does_not_gain_a_symbol() {
        let source = "<?php new Runner; new Runner;";
        let result = structure(source, "tests/Feature/run.php", false);
        assert_eq!(result.declarations[0][0], "(file) tests/Feature/run.php");
        assert_eq!(result.edges.len(), 1);
        assert_eq!(
            structure(source, "tests/Feature/run.php", true).edges.len(),
            2
        );
        assert!(
            structure(source, "app/run.php", true)
                .declarations
                .is_empty()
        );
        assert!(structure(source, "app/run.php", true).edges.is_empty());
    }
    #[test]
    fn relation_arguments_do_not_gain_a_second_class_reference() {
        let result = structure(
            "<?php class Runner { function run() { $this->hasMany(Model::class); Model::VALUE; self::run(); } }",
            "app/Runner.php",
            false,
        );
        assert_eq!(
            result.edges,
            json!([
                ["Runner", "Model", 1, "eloquent-relation", false],
                ["Runner", "Model", 1, "class-constant", true]
            ])
            .as_array()
            .unwrap()
            .clone()
        );
    }

    #[test]
    fn matches_the_frozen_php_structural_oracle() {
        let fixtures: Value = serde_json::from_str(include_str!(
            "../../tests/Fixtures/Analysis/basic-structure.json"
        ))
        .unwrap();
        for fixture in fixtures["cases"].as_array().unwrap() {
            let result = structure(
                fixture["source"].as_str().unwrap(),
                fixture["path"].as_str().unwrap(),
                fixture["preserve_occurrences"].as_bool().unwrap(),
            );
            assert_eq!(
                json!(result.declarations),
                fixture["declarations"],
                "{} declarations",
                fixture["id"]
            );
            assert_eq!(
                json!(result.edges),
                fixture["edges"],
                "{} edges",
                fixture["id"]
            );
        }
    }
}
