<?php

return [
    'required' => 'Le champ :attribute est obligatoire.', 'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.', 'unique' => 'Cette valeur est déjà utilisée.',
    'exists' => 'La valeur sélectionnée est invalide.', 'date' => 'Le champ :attribute doit être une date valide.',
    'numeric' => 'Le champ :attribute doit être un nombre.', 'integer' => 'Le champ :attribute doit être un nombre entier.',
    'min' => ['numeric' => 'Le champ :attribute doit être au moins :min.', 'string' => 'Le champ :attribute doit contenir au moins :min caractères.', 'array' => 'Le champ :attribute doit contenir au moins :min éléments.', 'file' => 'Le fichier :attribute doit faire au moins :min kilo-octets.'],
    'max' => ['numeric' => 'Le champ :attribute ne doit pas dépasser :max.', 'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.', 'array' => 'Le champ :attribute ne doit pas dépasser :max éléments.', 'file' => 'Le fichier :attribute ne doit pas dépasser :max kilo-octets.'],
    'image' => 'Le fichier :attribute doit être une image.', 'mimes' => 'Le fichier :attribute doit être de type :values.',
    'password' => ['letters' => 'Le mot de passe doit contenir au moins une lettre.', 'mixed' => 'Le mot de passe doit contenir des majuscules et des minuscules.', 'numbers' => 'Le mot de passe doit contenir au moins un chiffre.', 'symbols' => 'Le mot de passe doit contenir au moins un symbole.', 'uncompromised' => 'Ce mot de passe apparaît dans une fuite de données. Choisissez-en un autre.'],
];
