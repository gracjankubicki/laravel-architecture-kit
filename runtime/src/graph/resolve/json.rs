//! PHP descriptor JSON keeps object key order for strict signature shape checks.
use serde::{
    Deserializer, Serialize, Serializer,
    de::{self, DeserializeSeed, MapAccess, SeqAccess, Visitor},
    ser::{SerializeMap, SerializeSeq},
};
use serde_json::Value;
use std::fmt;
enum Json {
    Scalar(Value),
    Array(Vec<Json>),
    Object(Vec<(String, Json)>),
}
struct Seed(usize);
struct Walk(usize);
impl<'de> DeserializeSeed<'de> for Seed {
    type Value = Json;
    fn deserialize<D: Deserializer<'de>>(self, d: D) -> Result<Json, D::Error> {
        if self.0 > 32 {
            return Err(de::Error::custom("descriptor_depth"));
        }
        d.deserialize_any(Walk(self.0))
    }
}
impl<'de> Visitor<'de> for Walk {
    type Value = Json;
    fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
        f.write_str("descriptor JSON")
    }
    fn visit_unit<E: de::Error>(self) -> Result<Json, E> {
        Ok(Json::Scalar(Value::Null))
    }
    fn visit_bool<E: de::Error>(self, v: bool) -> Result<Json, E> {
        Ok(Json::Scalar(v.into()))
    }
    fn visit_i64<E: de::Error>(self, v: i64) -> Result<Json, E> {
        Ok(Json::Scalar(v.into()))
    }
    fn visit_u64<E: de::Error>(self, v: u64) -> Result<Json, E> {
        Ok(Json::Scalar(v.into()))
    }
    fn visit_f64<E: de::Error>(self, v: f64) -> Result<Json, E> {
        Ok(Json::Scalar(
            serde_json::Number::from_f64(v)
                .ok_or_else(|| E::custom("nonfinite"))?
                .into(),
        ))
    }
    fn visit_str<E: de::Error>(self, v: &str) -> Result<Json, E> {
        Ok(Json::Scalar(v.into()))
    }
    fn visit_string<E: de::Error>(self, v: String) -> Result<Json, E> {
        Ok(Json::Scalar(v.into()))
    }
    fn visit_seq<A: SeqAccess<'de>>(self, mut a: A) -> Result<Json, A::Error> {
        let mut v = vec![];
        while let Some(item) = a.next_element_seed(Seed(self.0 + 1))? {
            v.push(item)
        }
        Ok(Json::Array(v))
    }
    fn visit_map<A: MapAccess<'de>>(self, mut a: A) -> Result<Json, A::Error> {
        let mut v: Vec<(String, Json)> = vec![];
        while let Some(key) = a.next_key::<String>()? {
            let value = a.next_value_seed(Seed(self.0 + 1))?;
            if let Some((_, old)) = v.iter_mut().find(|(k, _)| *k == key) {
                *old = value
            } else {
                v.push((key, value))
            }
        }
        Ok(Json::Object(v))
    }
}
impl Json {
    fn keys(&self) -> Vec<&str> {
        if let Self::Object(v) = self {
            v.iter().map(|(k, _)| k.as_str()).collect()
        } else {
            vec![]
        }
    }
    fn into_value(self) -> Value {
        match self {
            Self::Scalar(v) => v,
            Self::Array(v) => Value::Array(v.into_iter().map(Json::into_value).collect()),
            Self::Object(v) => {
                Value::Object(v.into_iter().map(|(k, v)| (k, v.into_value())).collect())
            }
        }
    }
}
pub fn decode(text: &str) -> Option<Value> {
    let mut d = serde_json::Deserializer::from_str(text);
    let v = Seed(0).deserialize(&mut d).ok()?;
    d.end().ok()?;
    if let Json::Array(a) = &v {
        if a.len() == 7 {
            let context = a[4].keys();
            if context != ["creator", "form", "binding"]
                && context != ["creator", "form", "binding", "factory_call"]
            {
                return None;
            }
            let Json::Array(args) = &a[5] else {
                return None;
            };
            if args
                .iter()
                .any(|arg| arg.keys() != ["name", "unpack", "by_ref"])
            {
                return None;
            }
        }
    }
    Some(v.into_value())
}
struct Ordered<'a>(&'a Value);
impl Serialize for Ordered<'_> {
    fn serialize<S: Serializer>(&self, s: S) -> Result<S::Ok, S::Error> {
        match self.0 {
            Value::Object(v) => {
                let fields: Vec<&str> = if v.len() == 3
                    && v.contains_key("name")
                    && v.contains_key("unpack")
                    && v.contains_key("by_ref")
                {
                    vec!["name", "unpack", "by_ref"]
                } else if (v.len() == 3 || v.len() == 4)
                    && v.contains_key("creator")
                    && v.contains_key("form")
                    && v.contains_key("binding")
                {
                    let mut keys = vec!["creator", "form", "binding"];
                    if v.contains_key("factory_call") {
                        keys.push("factory_call")
                    }
                    keys
                } else {
                    v.keys().map(String::as_str).collect()
                };
                let mut m = s.serialize_map(Some(fields.len()))?;
                for k in fields {
                    m.serialize_entry(k, &Ordered(&v[k]))?
                }
                m.end()
            }
            Value::Array(v) => {
                let mut seq = s.serialize_seq(Some(v.len()))?;
                for i in v {
                    seq.serialize_element(&Ordered(i))?
                }
                seq.end()
            }
            v => v.serialize(s),
        }
    }
}
pub fn encode(v: &Value) -> String {
    serde_json::to_string(&Ordered(v)).expect("finite descriptor JSON")
}
