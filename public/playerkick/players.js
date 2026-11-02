// Player Kick: the 100 players ready in the menu (name on the board, c = national team: the shirt colours).
// The owner adds the photos himself (menu → 👥 Players & photos); he can rename, delete and add more players.
export const PLAYERS = [
  // ⭐ superstars
  ['messi', 'MESSI', 'ar'], ['ronaldo', 'RONALDO', 'pt'], ['neymar', 'NEYMAR', 'br'], ['mbappe', 'MBAPPÉ', 'fr'], ['haaland', 'HAALAND', 'no'],
  ['vinicius', 'VINÍCIUS JR', 'br'], ['yamal', 'LAMINE YAMAL', 'es'], ['salah', 'SALAH', 'eg'], ['bellingham', 'BELLINGHAM', 'gb'], ['kane', 'KANE', 'gb'],
  // 🇦🇷 Argentina
  ['dimaria', 'DI MARÍA', 'ar'], ['emartinez', 'E. MARTÍNEZ', 'ar'], ['alvarez', 'J. ÁLVAREZ', 'ar'], ['lautaro', 'LAUTARO', 'ar'], ['macallister', 'MAC ALLISTER', 'ar'],
  ['enzo', 'ENZO', 'ar'], ['depaul', 'DE PAUL', 'ar'], ['otamendi', 'OTAMENDI', 'ar'], ['garnacho', 'GARNACHO', 'ar'],
  // 🇧🇷 Brazil
  ['rodrygo', 'RODRYGO', 'br'], ['raphinha', 'RAPHINHA', 'br'], ['endrick', 'ENDRICK', 'br'], ['alisson', 'ALISSON', 'br'], ['marquinhos', 'MARQUINHOS', 'br'],
  ['casemiro', 'CASEMIRO', 'br'], ['guimaraes', 'B. GUIMARÃES', 'br'], ['militao', 'MILITÃO', 'br'], ['savinho', 'SAVINHO', 'br'],
  // 🇵🇹 Portugal
  ['brunof', 'B. FERNANDES', 'pt'], ['bernardo', 'BERNARDO', 'pt'], ['leao', 'LEÃO', 'pt'], ['rdias', 'RÚBEN DIAS', 'pt'], ['felix', 'JOÃO FÉLIX', 'pt'],
  ['vitinha', 'VITINHA', 'pt'], ['jneves', 'JOÃO NEVES', 'pt'], ['nmendes', 'NUNO MENDES', 'pt'],
  // 🇫🇷 France
  ['griezmann', 'GRIEZMANN', 'fr'], ['dembele', 'DEMBÉLÉ', 'fr'], ['kante', 'KANTÉ', 'fr'], ['camavinga', 'CAMAVINGA', 'fr'], ['tchouameni', 'TCHOUAMÉNI', 'fr'],
  ['saliba', 'SALIBA', 'fr'], ['maignan', 'MAIGNAN', 'fr'], ['olise', 'OLISE', 'fr'], ['theo', 'THÉO', 'fr'],
  // 🏴 England
  ['saka', 'SAKA', 'gb'], ['foden', 'FODEN', 'gb'], ['rice', 'RICE', 'gb'], ['palmer', 'PALMER', 'gb'], ['rashford', 'RASHFORD', 'gb'],
  ['trent', 'ALEXANDER-ARNOLD', 'gb'], ['pickford', 'PICKFORD', 'gb'], ['stones', 'STONES', 'gb'],
  // 🇪🇸 Spain
  ['pedri', 'PEDRI', 'es'], ['gavi', 'GAVI', 'es'], ['rodri', 'RODRI', 'es'], ['nico', 'NICO WILLIAMS', 'es'], ['morata', 'MORATA', 'es'],
  ['olmo', 'DANI OLMO', 'es'], ['unai', 'UNAI SIMÓN', 'es'], ['carvajal', 'CARVAJAL', 'es'],
  // 🇩🇪 Germany
  ['musiala', 'MUSIALA', 'de'], ['wirtz', 'WIRTZ', 'de'], ['kimmich', 'KIMMICH', 'de'], ['havertz', 'HAVERTZ', 'de'], ['rudiger', 'RÜDIGER', 'de'],
  ['neuer', 'NEUER', 'de'], ['sane', 'SANÉ', 'de'], ['gundogan', 'GÜNDOĞAN', 'de'],
  // 🇳🇱 🇧🇪 🇭🇷 🇵🇱 🇲🇦
  ['vandijk', 'VAN DIJK', 'nl'], ['dejong', 'DE JONG', 'nl'], ['gakpo', 'GAKPO', 'nl'], ['depay', 'DEPAY', 'nl'],
  ['debruyne', 'DE BRUYNE', 'be'], ['courtois', 'COURTOIS', 'be'], ['lukaku', 'LUKAKU', 'be'], ['doku', 'DOKU', 'be'],
  ['modric', 'MODRIĆ', 'hr'], ['gvardiol', 'GVARDIOL', 'hr'], ['kovacic', 'KOVAČIĆ', 'hr'],
  ['lewandowski', 'LEWANDOWSKI', 'pl'], ['szczesny', 'SZCZĘSNY', 'pl'],
  ['hakimi', 'HAKIMI', 'ma'], ['ziyech', 'ZIYECH', 'ma'], ['bounou', 'BOUNOU', 'ma'], ['brahim', 'BRAHIM DÍAZ', 'ma'],
  // 🌍 more fan favourites
  ['son', 'SON', 'kr'], ['kvara', 'KVARATSKHELIA', 'ge'], ['osimhen', 'OSIMHEN', 'ng'], ['mane', 'MANÉ', 'sn'], ['valverde', 'VALVERDE', 'uy'],
  ['nunez', 'NÚÑEZ', 'uy'], ['odegaard', 'ØDEGAARD', 'no'], ['lookman', 'LOOKMAN', 'ng'], ['kubo', 'KUBO', 'jp'], ['pulisic', 'PULISIC', 'us'],
  ['davies', 'A. DAVIES', 'ca'], ['mitoma', 'MITOMA', 'jp'], ['isak', 'ISAK', 'se'], ['gyokeres', 'GYÖKERES', 'se'],
].map(([id, name, c]) => ({ id, name, c }));
export const DEFAULT_SIX = ['messi', 'ronaldo', 'neymar', 'mbappe', 'haaland', 'vinicius'];
