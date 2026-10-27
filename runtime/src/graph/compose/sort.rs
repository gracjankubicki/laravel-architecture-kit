//! PHP 8.5 zend_sort comparison schedule, including stable original-position ties.
//! PHP string comparison is non-transitive for mixed numeric/non-numeric strings;
//! Rust slice sorting is therefore not a valid substitute.
// Algorithm derived from PHP Zend/zend_sort.c (php-8.5.10), itself derived
// from LLVM libc++ std::sort. License retained below.
/*
 * ===========================================================================
 * libc++ License
 * ===========================================================================
 *
 * The libc++ library is dual licensed under both the University of Illinois
 * "BSD-Like" license and the MIT license. As a user of this code you may
 * choose to use it under either license. As a contributor, you agree to allow
 * your code to be used under both.
 *
 * Full text of the relevant licenses is included below.
 *
 * ===========================================================================
 *
 * University of Illinois/NCSA
 * Open Source License
 *
 * Copyright (c) 2009-2012 by the contributors listed at
 * http://llvm.org/svn/llvm-project/libcxx/trunk/CREDITS.TXT
 *
 * All rights reserved.
 *
 * Developed by:
 *
 *     LLVM Team
 *
 *     University of Illinois at Urbana-Champaign
 *
 *     http://llvm.org
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal with the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 *     * Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimers.
 *
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimers in the
 *       documentation and/or other materials provided with the distribution.
 *
 *     * Neither the names of the LLVM Team, University of Illinois at
 *       Urbana-Champaign, nor the names of its contributors may be used to
 *       endorse or promote products derived from this Software without
 *       specific prior written permission.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * CONTRIBUTORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * WITH THE SOFTWARE.
 *
 * ===========================================================================
 *
 * Copyright (c) 2009-2012 by the contributors listed at
 * http://llvm.org/svn/llvm-project/libcxx/trunk/CREDITS.TXT
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 */
use std::cmp::Ordering;
/// Include this notice in binary distributions, for example via a --licenses command.
pub const NOTICE: &str = include_str!("SORT-NOTICE.txt");

pub fn php_sort<T>(items: &mut [T], cmp: impl Fn(&T, &T) -> Ordering) {
    let positions: Vec<usize> = (0..items.len()).collect();
    let mut sort = Sort {
        items,
        positions,
        cmp,
    };
    sort.range(0, sort.items.len());
}
struct Sort<'a, T, F> {
    items: &'a mut [T],
    positions: Vec<usize>,
    cmp: F,
}
impl<T, F: Fn(&T, &T) -> Ordering> Sort<'_, T, F> {
    fn greater(&self, a: usize, b: usize) -> bool {
        (self.cmp)(&self.items[a], &self.items[b])
            .then_with(|| self.positions[a].cmp(&self.positions[b]))
            .is_gt()
    }
    fn swap(&mut self, a: usize, b: usize) {
        self.items.swap(a, b);
        self.positions.swap(a, b);
    }
    fn three(&mut self, a: usize, b: usize, c: usize) {
        if !self.greater(a, b) {
            if !self.greater(b, c) {
                return;
            }
            self.swap(b, c);
            if self.greater(a, b) {
                self.swap(a, b);
            }
        } else if !self.greater(c, b) {
            self.swap(a, c);
        } else {
            self.swap(a, b);
            if self.greater(b, c) {
                self.swap(b, c);
            }
        }
    }
    fn small(&mut self, indexes: &[usize]) {
        let n = indexes.len();
        if n == 2 {
            if self.greater(indexes[0], indexes[1]) {
                self.swap(indexes[0], indexes[1]);
            }
        } else if n >= 3 {
            self.three(indexes[0], indexes[1], indexes[2]);
            for i in 3..n {
                let mut j = i;
                while j > 0 && self.greater(indexes[j - 1], indexes[j]) {
                    self.swap(indexes[j - 1], indexes[j]);
                    j -= 1;
                }
            }
        }
    }
    fn insert(&mut self, start: usize, len: usize) {
        if len <= 5 {
            self.small(&(start..start + len).collect::<Vec<_>>());
            return;
        }
        for i in start + 1..start + 6 {
            let mut j = i - 1;
            if !self.greater(j, i) {
                continue;
            }
            while j != start {
                j -= 1;
                if !self.greater(j, i) {
                    j += 1;
                    break;
                }
            }
            for k in (j + 1..=i).rev() {
                self.swap(k, k - 1);
            }
        }
        for i in start + 6..start + len {
            let mut j = i - 1;
            if !self.greater(j, i) {
                continue;
            }
            loop {
                j -= 2;
                if !self.greater(j, i) {
                    j += 1;
                    if !self.greater(j, i) {
                        j += 1;
                    }
                    break;
                }
                if j == start {
                    break;
                }
                if j == start + 1 {
                    j -= 1;
                    if self.greater(i, j) {
                        j += 1;
                    }
                    break;
                }
            }
            for k in (j + 1..=i).rev() {
                self.swap(k, k - 1);
            }
        }
    }
    fn range(&mut self, mut start: usize, mut len: usize) {
        loop {
            if len <= 16 {
                self.insert(start, len);
                return;
            }
            let end = start + len;
            let offset = len >> 1;
            let pivot = start + offset;
            if len >> 10 != 0 {
                let delta = offset >> 1;
                self.small(&[start, start + delta, pivot, pivot + delta, end - 1]);
            } else {
                self.three(start, pivot, end - 1);
            }
            self.swap(start + 1, pivot);
            let pivot = start + 1;
            let mut i = pivot + 1;
            let mut j = end - 1;
            'partition: loop {
                while self.greater(pivot, i) {
                    i += 1;
                    if i == j {
                        break 'partition;
                    }
                }
                j -= 1;
                if j == i {
                    break;
                }
                while self.greater(j, pivot) {
                    j -= 1;
                    if j == i {
                        break 'partition;
                    }
                }
                self.swap(i, j);
                i += 1;
                if i == j {
                    break;
                }
            }
            self.swap(pivot, i - 1);
            let left = i - start - 1;
            let right = end - i;
            if left < right {
                self.range(start, left);
                start = i;
                len = right;
            } else {
                self.range(i, right);
                len = left;
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::graph::{compose::value::php_cmp, snapshot::hash};
    #[test]
    fn php85_golden_order_covers_insert_partition_and_five_pivot_schedules() {
        let names = [
            "2", "10", "1a", "01", "0", "00", "1.0", "1b", "9", "99", "100", "100a",
        ];
        // PHP 8.5.10 usort oracle, seed zend-v1, exact order hashed without normalization.
        for (n, expected) in [
            (
                0usize,
                "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            ),
            (
                1usize,
                "5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9",
            ),
            (
                2usize,
                "83b97b859aa5f81b2f0f86ba2a675efaf515ad2d5e2b8652cf2de7e1c2267350",
            ),
            (
                3usize,
                "cea67d8b58d72bb9387778326578aa3e58971aeb9f43ff6eaa599fbdeb5a3103",
            ),
            (
                4usize,
                "db3dc56f8c4223349a9d8e7c22a65b1c3080a1197d593f6eb43d59f5e24eba40",
            ),
            (
                5usize,
                "cb5d90d7e06b53ac78791919eeaf386c5627905e42f196bc875aac9547a39105",
            ),
            (
                6usize,
                "baa06bdd42b704f7fab349d658b3c8d8084c1c4c00b9d21f73ececddca532e84",
            ),
            (
                7usize,
                "6f7cc1b93fad643b1e0a9dca33d9b216417fe81b081fe0a566d54bbd20fed17c",
            ),
            (
                8usize,
                "9aaf3a6912cc3b2eaf40bdfe7777320fc5c2094dfa2bf8d381272c5b88b38d15",
            ),
            (
                15usize,
                "f2a72614f17043b2e24a3c5c3f924d249e8e0ced54ca13fc523383001b813e51",
            ),
            (
                16usize,
                "dea403cca940c08f9278dc3bc511bcc6644351acd8b9ce99fdb82f11796c36c4",
            ),
            (
                17usize,
                "c4a2f9b1afc4e4f2d90640469df918b150efd31d8027dde3423b9c0705380564",
            ),
            (
                80usize,
                "8ad5fd3524bbda350d310fbf26698156d2d5cf26fd88944f9602075a1919a304",
            ),
            (
                1024usize,
                "b490fee896e42f09a09289aa2aca21e4328af0da51555eb0f4b9d1d4c99c7e7b",
            ),
            (
                1025usize,
                "897972fdeb9b90c7d194080921d7231c718f547f1c7dd6832d7564c951e2a924",
            ),
            (
                2050usize,
                "3157892d05eca2e72b750a9daf3abdf721223220996864ef70eb5413af21b0a6",
            ),
        ] {
            let mut items: Vec<_> = (0..n).collect();
            items.sort_by_key(|i| hash(format!("zend-v1-{i}").as_bytes()));
            php_sort(&mut items, |a, b| {
                php_cmp(names[*a % names.len()], names[*b % names.len()])
            });
            let encoded = items
                .iter()
                .map(usize::to_string)
                .collect::<Vec<_>>()
                .join(",");
            assert_eq!(hash(encoded.as_bytes()), expected, "size {n}");
        }
    }
}
