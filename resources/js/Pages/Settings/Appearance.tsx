import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useState, useRef } from 'react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { PageProps } from '@/types';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Upload, X } from 'lucide-react';

interface AppearanceSettingsProps extends PageProps {
    settings: Record<string, string>;
}

export default function Appearance({ settings }: AppearanceSettingsProps) {
    const { data, setData, post, processing, errors } = useForm({
        logo: null as File | null,
        accent_color: settings.accent_color || '#3b82f6',
    });

    const [logoPreview, setLogoPreview] = useState<string | null>(
        settings.logo_url || null
    );
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleLogoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('logo', file);
            const reader = new FileReader();
            reader.onloadend = () => {
                setLogoPreview(reader.result as string);
            };
            reader.readAsDataURL(file);
        }
    };

    const clearLogo = () => {
        setData('logo', null);
        setLogoPreview(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('settings.appearance.update'), {
            forceFormData: true,
        });
    };

    return (
        <SettingsLayout>
            <Head title="Appearance Settings" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight">Appearance</h1>
                    <p className="text-muted-foreground">
                        Customize the look and feel of your help desk
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Branding</CardTitle>
                        <CardDescription>
                            Upload your logo and customize colors
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            <div className="space-y-4">
                                <Label>Logo</Label>
                                <div className="space-y-4">
                                    {logoPreview ? (
                                        <div className="relative inline-block">
                                            <div className="rounded-lg border border-border bg-muted p-4">
                                                <img
                                                    src={logoPreview}
                                                    alt="Logo preview"
                                                    className="max-h-32 max-w-xs object-contain"
                                                />
                                            </div>
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                size="icon"
                                                className="absolute -right-2 -top-2 h-6 w-6 rounded-full"
                                                onClick={clearLogo}
                                            >
                                                <X className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-center rounded-lg border-2 border-dashed border-border p-12">
                                            <div className="text-center">
                                                <Upload className="mx-auto h-12 w-12 text-muted-foreground" />
                                                <p className="mt-2 text-sm text-muted-foreground">
                                                    No logo uploaded
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                    <div>
                                        <Input
                                            ref={fileInputRef}
                                            type="file"
                                            accept="image/*"
                                            onChange={handleLogoChange}
                                            className="max-w-xs"
                                        />
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            PNG, JPG, or SVG. Max 2MB. Recommended size: 200x50px
                                        </p>
                                    </div>
                                </div>
                                {errors.logo && (
                                    <p className="text-sm text-destructive">{errors.logo}</p>
                                )}
                            </div>

                            <div className="space-y-4">
                                <Label htmlFor="accent_color">Accent Color</Label>
                                <div className="flex gap-4">
                                    <div className="flex items-center gap-3">
                                        <Input
                                            id="accent_color"
                                            type="color"
                                            value={data.accent_color}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            className="h-12 w-20 cursor-pointer"
                                        />
                                        <Input
                                            type="text"
                                            value={data.accent_color}
                                            onChange={(e) => setData('accent_color', e.target.value)}
                                            placeholder="#3b82f6"
                                            className="w-32 font-mono"
                                            pattern="^#[0-9A-Fa-f]{6}$"
                                        />
                                    </div>
                                    <div
                                        className="flex h-12 w-12 items-center justify-center rounded-lg border"
                                        style={{ backgroundColor: data.accent_color }}
                                    >
                                        <div className="h-6 w-6 rounded-full bg-white/30" />
                                    </div>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    This color will be used for buttons, links, and other UI elements
                                </p>
                                {errors.accent_color && (
                                    <p className="text-sm text-destructive">{errors.accent_color}</p>
                                )}
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Saving...' : 'Save Changes'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </SettingsLayout>
    );
}
