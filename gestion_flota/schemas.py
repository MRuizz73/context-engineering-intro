"""Esquemas Pydantic para entrada/salida de la API."""

from datetime import date
from typing import List, Optional

from pydantic import BaseModel, ConfigDict, Field, model_validator

from .models import EstadoDocumento, RolUsuario, TipoDocumento


class Credenciales(BaseModel):
    """Datos de registro o inicio de sesión."""

    username: str = Field(min_length=3, max_length=50)
    password: str = Field(min_length=6, max_length=128)
    # Reason: si CODIGO_REGISTRO está configurado, solo puede crear cuenta
    # quien conozca el código interno de la empresa.
    codigo: Optional[str] = None
    # Al registrarse como chofer: email cargado en su perfil, para vincular
    # la cuenta con el chofer correcto.
    email_chofer: Optional[str] = None


class CambioPassword(BaseModel):
    """Datos para cambiar la contraseña de la propia cuenta."""

    password_actual: str
    password_nueva: str = Field(min_length=6, max_length=128)


class UsuarioRead(BaseModel):
    """Usuario autenticado (sin datos sensibles)."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    username: str
    rol: RolUsuario = RolUsuario.ADMIN
    chofer_id: Optional[int] = None


class ChoferCreate(BaseModel):
    """Datos para crear o actualizar un chofer."""

    nombre: str
    apellido: str
    dni: str
    telefono: Optional[str] = None
    email: Optional[str] = None
    activo: bool = True


class CamionCreate(BaseModel):
    """Datos para crear o actualizar un camión."""

    patente: str
    marca: Optional[str] = None
    modelo: Optional[str] = None
    anio: Optional[int] = None
    activo: bool = True


class DocumentoCreate(BaseModel):
    """Datos para crear o actualizar un documento (curso/permiso)."""

    nombre: str
    tipo: TipoDocumento = TipoDocumento.CURSO
    fecha_emision: Optional[date] = None
    fecha_vencimiento: date
    dias_aviso: int = 30
    notas: Optional[str] = None
    chofer_id: Optional[int] = None
    camion_id: Optional[int] = None

    @model_validator(mode="after")
    def validar_titular(self) -> "DocumentoCreate":
        """
        Valida que el documento pertenezca a exactamente un titular.

        Returns:
            DocumentoCreate: la instancia validada.

        Raises:
            ValueError: si no se indica titular o se indican ambos.
        """
        if bool(self.chofer_id) == bool(self.camion_id):
            raise ValueError(
                "El documento debe pertenecer a un chófer O a un camión (exactamente uno)."
            )
        if self.dias_aviso < 0:
            raise ValueError("dias_aviso no puede ser negativo.")
        return self


class RenovacionDocumento(BaseModel):
    """Datos para renovar un documento (nueva vigencia tras el trámite)."""

    fecha_vencimiento: date
    fecha_emision: Optional[date] = None


class DocumentoRead(BaseModel):
    """Documento con su estado de vencimiento calculado."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    nombre: str
    tipo: TipoDocumento
    fecha_emision: Optional[date]
    fecha_vencimiento: date
    dias_aviso: int
    notas: Optional[str]
    chofer_id: Optional[int]
    camion_id: Optional[int]
    estado: EstadoDocumento
    dias_restantes: int
    titular: str
    email_destino: Optional[str] = None


class ChoferRead(BaseModel):
    """Chofer con la cantidad de alertas de sus documentos."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    nombre: str
    apellido: str
    dni: str
    telefono: Optional[str]
    email: Optional[str]
    activo: bool
    documentos: List[DocumentoRead] = []


class CamionRead(BaseModel):
    """Camión con sus documentos."""

    model_config = ConfigDict(from_attributes=True)

    id: int
    patente: str
    marca: Optional[str]
    modelo: Optional[str]
    anio: Optional[int]
    activo: bool
    documentos: List[DocumentoRead] = []
